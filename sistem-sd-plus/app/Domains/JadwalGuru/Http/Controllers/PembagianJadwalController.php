<?php

namespace App\Domains\JadwalGuru\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Domains\JadwalGuru\Models\JadwalMengajar;
use App\Domains\JadwalGuru\Models\KisiKisiTugas;
use App\Domains\JadwalGuru\Models\WaktuPelajaran;
use App\Domains\JadwalGuru\Services\JadwalService;
use App\Models\Kelas;
use App\Models\TahunAjaran;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class PembagianJadwalController extends Controller
{
    public function __construct(
        private readonly JadwalService $jadwalService
    ) {
    }

    /** Menampilkan papan pembagian jadwal untuk tahun ajaran aktif. */
    public function index(): Response
    {
        $tahunAjaran = TahunAjaran::aktif()->firstOrFail();

        $slotKbm = WaktuPelajaran::query()
            ->where('tahun_ajaran_id', $tahunAjaran->id)
            ->where('jenis_kegiatan', 'kbm')
            ->withCount('hariBerlaku')
            ->get();

        $daftarKelas = Kelas::query()
            ->where('tahun_ajaran_id', $tahunAjaran->id)
            ->orderBy('jenjang')
            ->orderBy('nama_kelas')
            ->get()
            ->map(function (Kelas $kelas) use ($slotKbm): array {
                $kuotaJam = (int) $slotKbm
                    ->filter(fn (WaktuPelajaran $slot) =>
                        $kelas->jenjang >= $slot->jenjang_min &&
                        $kelas->jenjang <= $slot->jenjang_max
                    )
                    ->sum('hari_berlaku_count');

                return [
                    'id' => $kelas->id,
                    'nama_kelas' => $kelas->nama_kelas,
                    'nama_lengkap' => $kelas->nama_lengkap,
                    'jenjang' => (int) $kelas->jenjang,
                    'kuota_jam' => $kuotaJam,
                ];
            })
            ->values();

        $waktuPelajaran = WaktuPelajaran::query()
            ->where('tahun_ajaran_id', $tahunAjaran->id)
            ->with('hariBerlaku')
            ->orderBy('urutan')
            ->get();

        $kisiKisiTugas = KisiKisiTugas::query()
            ->where('tahun_ajaran_id', $tahunAjaran->id)
            ->with(['guru.user', 'kelas', 'mataPelajaran', 'jenisTugas'])
            ->withCount('jadwalMengajar')
            ->orderBy('guru_id')
            ->orderBy('jenis_tugas_id')
            ->get()
            ->map(function (KisiKisiTugas $kisi): KisiKisiTugas {
                $kisi->sisa_jam_tersedia = max(
                    0,
                    (int) $kisi->jumlah_jam - (int) $kisi->jadwal_mengajar_count
                );

                return $kisi;
            })
            ->filter(fn (KisiKisiTugas $kisi) => $kisi->sisa_jam_tersedia > 0)
            ->values();

        $jadwalTerplot = JadwalMengajar::query()
            ->whereHas('kisiKisiTugas', fn ($query) => $query->where('tahun_ajaran_id', $tahunAjaran->id))
            ->with([
                'guru.user',
                'kisiKisiTugas.mataPelajaran',
                'jenisTugas',
            ])
            ->orderBy('hari')
            ->orderBy('waktu_pelajaran_id')
            ->get();

        return Inertia::render('jadwal-guru/PembagianJadwal', [
            'tahunAjaran' => [
                'id' => $tahunAjaran->id,
                'nama' => $tahunAjaran->nama,
            ],
            'waktuPelajaran' => $waktuPelajaran,
            'daftarKelas' => $daftarKelas,
            'kisiKisiTugas' => $kisiKisiTugas,
            'jadwalTerplot' => $jadwalTerplot,
        ]);
    }

    /** Menyimpan satu penempatan dari aksi drag-and-drop. */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'kisi_kisi_tugas_id' => ['required', 'integer', 'exists:kisi_kisi_tugas,id'],
            'waktu_pelajaran_id' => ['required', 'integer', 'exists:waktu_pelajaran,id'],
            'hari' => ['required', 'integer', 'between:1,5'],
            'parent_jadwal_id' => ['nullable', 'integer', 'exists:jadwal_mengajar,id'],
        ]);

        try {
            $this->jadwalService->assignJadwal(
                (int) $validated['kisi_kisi_tugas_id'],
                (int) $validated['waktu_pelajaran_id'],
                (int) $validated['hari'],
                isset($validated['parent_jadwal_id']) ? (int) $validated['parent_jadwal_id'] : null
            );

            return redirect()->back()->with('success', 'Jadwal berhasil ditempatkan.');
        } catch (Throwable $exception) {
            return redirect()->back()->withErrors([
                'message' => $exception->getMessage(),
            ]);
        }
    }

    /** Menghapus jadwal dan mengembalikan alokasinya ke sidebar. */
    public function destroy(int $id): RedirectResponse
    {
        try {
            $this->jadwalService->removeJadwal($id);

            return redirect()->back()->with('success', 'Jadwal berhasil ditarik kembali.');
        } catch (Throwable $exception) {
            return redirect()->back()->withErrors([
                'message' => 'Gagal menarik jadwal: ' . $exception->getMessage(),
            ]);
        }
    }
}
