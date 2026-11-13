<?php

namespace App\Domains\JadwalGuru\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Domains\JadwalGuru\Models\KisiKisiTugas;
use App\Domains\JadwalGuru\Models\WaktuPelajaran;
use App\Domains\JadwalGuru\Services\KisiKisiTugasService;
use App\Models\Guru;
use App\Models\Kelas;
use App\Models\MataPelajaran;
use App\Models\TahunAjaran;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class KisiKisiTugasController extends Controller
{
    public function __construct(
        private readonly KisiKisiTugasService $kisiKisiTugasService
    ) {
    }

    public function index(): Response
    {
        $tahunAjaran = TahunAjaran::aktif()->firstOrFail();

        $slotKbm = WaktuPelajaran::query()
            ->where('tahun_ajaran_id', $tahunAjaran->id)
            ->where('jenis_kegiatan', 'kbm')
            ->withCount('hariBerlaku')
            ->get();

        $alokasiPerKelas = KisiKisiTugas::query()
            ->select('kelas_id', DB::raw('SUM(jumlah_jam) AS total_jam'))
            ->where('tahun_ajaran_id', $tahunAjaran->id)
            ->whereNotNull('kelas_id')
            ->whereHas('jenisTugas', fn ($query) => $query->where('kode', 'utama'))
            ->groupBy('kelas_id')
            ->pluck('total_jam', 'kelas_id');

        $kelasList = Kelas::query()
            ->where('tahun_ajaran_id', $tahunAjaran->id)
            ->orderBy('jenjang')
            ->orderBy('nama_kelas')
            ->get()
            ->map(function (Kelas $kelas) use ($slotKbm, $alokasiPerKelas) {
                $kuota = (int) $slotKbm
                    ->filter(fn ($slot) =>
                        $kelas->jenjang >= $slot->jenjang_min &&
                        $kelas->jenjang <= $slot->jenjang_max
                    )
                    ->sum('hari_berlaku_count');

                return [
                    'id' => $kelas->id,
                    'nama_kelas' => $kelas->nama_kelas,
                    'nama_lengkap' => $kelas->nama_lengkap,
                    'jenjang' => (int) $kelas->jenjang,
                    'kuota_jam' => $kuota,
                    'teralokasi_jam' => (int) ($alokasiPerKelas[$kelas->id] ?? 0),
                ];
            })
            ->values();

        $guruList = Guru::query()
            ->with('user:id,name')
            ->where('status_aktif', true)
            ->orderBy('kode_guru')
            ->get()
            ->map(fn (Guru $guru) => [
                'id' => $guru->id,
                'kode_guru' => $guru->kode_guru,
                'nama' => $guru->user?->name ?? 'Nama guru belum tersedia',
            ])
            ->values();

        $mapelList = MataPelajaran::query()
            ->orderBy('nama')
            ->get(['id', 'kode', 'nama']);

        $alokasi = KisiKisiTugas::query()
            ->where('tahun_ajaran_id', $tahunAjaran->id)
            ->with([
                'guru.user:id,name',
                'kelas:id,nama_kelas,jenjang',
                'mataPelajaran:id,kode,nama',
                'jenisTugas:id,kode,nama',
            ])
            ->withCount('jadwalMengajar')
            ->orderBy('guru_id')
            ->orderBy('jenis_tugas_id')
            ->get()
            ->map(fn (KisiKisiTugas $item) => [
                'id' => $item->id,
                'guru_id' => $item->guru_id,
                'jenis_tugas_id' => $item->jenis_tugas_id,
                'kelas_id' => $item->kelas_id,
                'mapel_id' => $item->mapel_id,
                'jumlah_jam' => (int) $item->jumlah_jam,
                'jadwal_terpasang' => (int) $item->jadwal_mengajar_count,
                'sisa_jam' => max(0, (int) $item->jumlah_jam - (int) $item->jadwal_mengajar_count),
                'guru' => [
                    'id' => $item->guru?->id,
                    'kode_guru' => $item->guru?->kode_guru,
                    'nama' => $item->guru?->user?->name ?? 'Nama guru belum tersedia',
                ],
                'kelas' => $item->kelas ? [
                    'id' => $item->kelas->id,
                    'nama_kelas' => $item->kelas->nama_kelas,
                    'jenjang' => (int) $item->kelas->jenjang,
                ] : null,
                'mata_pelajaran' => $item->mataPelajaran ? [
                    'id' => $item->mataPelajaran->id,
                    'kode' => $item->mataPelajaran->kode,
                    'nama' => $item->mataPelajaran->nama,
                ] : null,
                'jenis_tugas' => [
                    'id' => $item->jenisTugas?->id,
                    'kode' => $item->jenisTugas?->kode,
                    'nama' => $item->jenisTugas?->nama,
                ],
            ])
            ->values();

        $summaryByType = $alokasi->groupBy('jenis_tugas.kode')->map(fn ($items) => [
            'jumlah_alokasi' => $items->count(),
            'jumlah_jam' => (int) $items->sum('jumlah_jam'),
        ]);

        return Inertia::render('jadwal-guru/KisiKisiTugas', [
            'tahunAjaran' => [
                'id' => $tahunAjaran->id,
                'nama' => $tahunAjaran->nama,
            ],
            'guruList' => $guruList,
            'kelasList' => $kelasList,
            'mapelList' => $mapelList,
            'kisiKisiTugas' => $alokasi,
            'summary' => [
                'jumlah_alokasi' => $alokasi->count(),
                'total_jam' => (int) $alokasi->sum('jumlah_jam'),
                'per_jenis' => $summaryByType,
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $tahunAjaran = TahunAjaran::aktif()->firstOrFail();
        $validated = $this->validateAllocation($request);

        $this->kisiKisiTugasService->store($tahunAjaran->id, $validated);

        return redirect()
            ->route('jadwal-guru.kisi-kisi.index')
            ->with('success', 'Alokasi tugas berhasil ditambahkan.');
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $tahunAjaran = TahunAjaran::aktif()->firstOrFail();
        $validated = $this->validateAllocation($request);
        $kisiKisi = KisiKisiTugas::query()
            ->where('tahun_ajaran_id', $tahunAjaran->id)
            ->findOrFail($id);

        $this->kisiKisiTugasService->update($kisiKisi, $tahunAjaran->id, $validated);

        return redirect()
            ->route('jadwal-guru.kisi-kisi.index')
            ->with('success', 'Alokasi tugas berhasil diperbarui.');
    }

    public function destroy(int $id): RedirectResponse
    {
        $tahunAjaran = TahunAjaran::aktif()->firstOrFail();
        $kisiKisi = KisiKisiTugas::query()
            ->where('tahun_ajaran_id', $tahunAjaran->id)
            ->findOrFail($id);

        $this->kisiKisiTugasService->delete($kisiKisi, $tahunAjaran->id);

        return redirect()
            ->route('jadwal-guru.kisi-kisi.index')
            ->with('success', 'Alokasi tugas berhasil dihapus.');
    }

    private function validateAllocation(Request $request): array
    {
        return $request->validate([
            'guru_id' => ['required', 'integer', 'exists:guru,id'],
            'jenis_tugas' => ['required', 'string', 'in:utama,damping,piket'],
            'kelas_id' => [
                'nullable',
                'integer',
                'required_if:jenis_tugas,utama',
                'prohibited_unless:jenis_tugas,utama',
                'exists:kelas,id',
            ],
            'mapel_id' => [
                'nullable',
                'integer',
                'required_if:jenis_tugas,utama',
                'prohibited_unless:jenis_tugas,utama',
                'exists:mata_pelajaran,id',
            ],
            'jumlah_jam' => ['required', 'integer', 'min:1', 'max:53'],
        ]);
    }
}
