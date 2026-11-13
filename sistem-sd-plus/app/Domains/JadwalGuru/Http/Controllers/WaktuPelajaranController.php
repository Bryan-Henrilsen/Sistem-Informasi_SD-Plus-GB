<?php

namespace App\Domains\JadwalGuru\Http\Controllers;

use App\Domains\JadwalGuru\Models\WaktuPelajaran;
use App\Domains\JadwalGuru\Services\WaktuPelajaranService;
use App\Http\Controllers\Controller;
use App\Models\TahunAjaran;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class WaktuPelajaranController extends Controller
{
    public function __construct(
        private readonly WaktuPelajaranService $waktuPelajaranService
    ) {
    }

    public function index(): Response
    {
        $tahunAjaran = TahunAjaran::aktif()->firstOrFail();

        $slots = WaktuPelajaran::query()
            ->where('tahun_ajaran_id', $tahunAjaran->id)
            ->with('hariBerlaku')
            ->withCount('jadwalMengajar')
            ->orderBy('urutan')
            ->orderBy('jam_mulai')
            ->get()
            ->map(fn (WaktuPelajaran $slot) => [
                'id' => $slot->id,
                'urutan' => (int) $slot->urutan,
                'jam_ke' => $slot->jam_ke === null ? null : (int) $slot->jam_ke,
                'nama' => $slot->nama,
                'jam_mulai' => substr((string) $slot->jam_mulai, 0, 5),
                'jam_selesai' => substr((string) $slot->jam_selesai, 0, 5),
                'durasi_menit' => $this->durationInMinutes($slot->jam_mulai, $slot->jam_selesai),
                'jenis_kegiatan' => $slot->jenis_kegiatan,
                'jenjang_min' => (int) $slot->jenjang_min,
                'jenjang_max' => (int) $slot->jenjang_max,
                'hari' => $slot->hariBerlaku->pluck('hari')->map(fn ($hari) => (int) $hari)->sort()->values(),
                'jadwal_terpasang' => (int) $slot->jadwal_mengajar_count,
                'is_used' => $slot->jadwal_mengajar_count > 0,
            ])
            ->values();

        return Inertia::render('jadwal-guru/WaktuPelajaran', [
            'tahunAjaran' => [
                'id' => $tahunAjaran->id,
                'nama' => $tahunAjaran->nama,
            ],
            'slots' => $slots,
            'summary' => [
                'total_slot' => $slots->count(),
                'total_kbm' => $slots->where('jenis_kegiatan', 'kbm')->count(),
                'total_istirahat' => $slots->where('jenis_kegiatan', 'istirahat')->count(),
                'total_upacara' => $slots->where('jenis_kegiatan', 'upacara')->count(),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $tahunAjaran = TahunAjaran::aktif()->firstOrFail();
        $data = $this->validateSlot($request, $tahunAjaran->id);

        $this->waktuPelajaranService->store($tahunAjaran->id, $data);

        return redirect()
            ->route('jadwal-guru.waktu-pelajaran.index')
            ->with('success', 'Waktu pelajaran berhasil ditambahkan.');
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $tahunAjaran = TahunAjaran::aktif()->firstOrFail();
        $slot = WaktuPelajaran::query()
            ->where('tahun_ajaran_id', $tahunAjaran->id)
            ->findOrFail($id);

        $data = $this->validateSlot($request, $tahunAjaran->id, $slot->id);
        $this->waktuPelajaranService->update($slot, $data);

        return redirect()
            ->route('jadwal-guru.waktu-pelajaran.index')
            ->with('success', 'Waktu pelajaran berhasil diperbarui.');
    }

    public function destroy(int $id): RedirectResponse
    {
        $tahunAjaran = TahunAjaran::aktif()->firstOrFail();
        $slot = WaktuPelajaran::query()
            ->where('tahun_ajaran_id', $tahunAjaran->id)
            ->findOrFail($id);

        $this->waktuPelajaranService->delete($slot);

        return redirect()
            ->route('jadwal-guru.waktu-pelajaran.index')
            ->with('success', 'Slot waktu pelajaran berhasil dihapus.');
    }

    private function validateSlot(Request $request, int $tahunAjaranId, ?int $ignoreId = null): array
    {
        $uniqueUrutan = Rule::unique('waktu_pelajaran', 'urutan')
            ->where(fn ($query) => $query->where('tahun_ajaran_id', $tahunAjaranId));

        if ($ignoreId !== null) {
            $uniqueUrutan->ignore($ignoreId);
        }

        $data = $request->validate([
            'urutan' => ['required', 'integer', 'min:1', $uniqueUrutan],
            'jam_ke' => ['nullable', 'integer', 'min:1'],
            'nama' => ['required', 'string', 'max:255'],
            'jam_mulai' => ['required', 'date_format:H:i'],
            'jam_selesai' => ['required', 'date_format:H:i'],
            'jenis_kegiatan' => ['required', 'string', Rule::in(['kbm', 'istirahat', 'upacara'])],
            'jenjang_min' => ['required', 'integer', 'between:1,6'],
            'jenjang_max' => ['required', 'integer', 'between:1,6', 'gte:jenjang_min'],
            'hari' => ['required', 'array', 'min:1'],
            'hari.*' => ['required', 'integer', 'distinct', 'between:1,5'],
        ]);

        if ($data['jenis_kegiatan'] === 'kbm' && empty($data['jam_ke'])) {
            throw ValidationException::withMessages([
                'jam_ke' => 'Nomor jam wajib diisi untuk kegiatan KBM.',
            ]);
        }

        if ($data['jenis_kegiatan'] !== 'kbm') {
            $data['jam_ke'] = null;
        }

        $mulai = strtotime($data['jam_mulai']);
        $selesai = strtotime($data['jam_selesai']);

        if ($selesai <= $mulai) {
            throw ValidationException::withMessages([
                'jam_selesai' => 'Waktu selesai harus lebih besar daripada waktu mulai.',
            ]);
        }

        // PostgreSQL TIME menerima format HH:MM:SS secara konsisten.
        $data['jam_mulai'] .= ':00';
        $data['jam_selesai'] .= ':00';
        $data['hari'] = array_map('intval', $data['hari']);

        return $data;
    }

    private function durationInMinutes(string $jamMulai, string $jamSelesai): int
    {
        $mulai = strtotime($jamMulai);
        $selesai = strtotime($jamSelesai);

        return max(0, (int) (($selesai - $mulai) / 60));
    }
}