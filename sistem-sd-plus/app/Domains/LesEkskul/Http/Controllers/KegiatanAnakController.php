<?php

namespace App\Domains\LesEkskul\Http\Controllers;

use App\Domains\LesEkskul\Enums\TipeKegiatan;
use App\Domains\LesEkskul\Models\Ekstrakurikuler;
use App\Domains\LesEkskul\Models\JadwalEkskul;
use App\Domains\LesEkskul\Models\LesTambahan;
use App\Domains\LesEkskul\Models\Pendaftaran;
use App\Domains\LesEkskul\Models\PeriodePendaftaran;
use App\Domains\LesEkskul\Services\PendaftaranService;
use App\Http\Controllers\Controller;
use App\Models\Siswa;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

// Kegiatan anak di sisi orang tua: kegiatan wajib (FR-24), kegiatan yang diikuti, dan pendaftaran mandiri
// selama periode pendaftaran (FR-2–FR-5, FR-26; Ringkasan v1.11 #37)
class KegiatanAnakController extends Controller
{
    public function __construct(private PendaftaranService $service) {}

    public function show(Request $request, Siswa $siswa): Response
    {
        $this->pastikanAnakSendiri($request, $siswa);
        $siswa->load('kelasTahunAktif.kelas');
        $jenjang = $siswa->kelasTahunAktif?->kelas?->jenjang;

        $aktif = $siswa->pendaftaran()->aktif()
            ->with(['lesTambahan.kelas', 'ekstrakurikuler.induk.jadwal', 'ekstrakurikuler.jadwal'])
            ->get();
        // Kegiatan yang sudah diikuti tidak ditawarkan lagi (kelas paralel dihitung sebagai induknya)
        $diikuti = $aktif->map(fn (Pendaftaran $p) => $p->les_tambahan_id ? 'les-'.$p->les_tambahan_id : 'ekskul-'.($p->ekstrakurikuler->parent_ekstrakurikuler_id ?? $p->ekstrakurikuler_id));
        $buka = $this->service->periodeBuka();

        return Inertia::render('les-ekskul/orang-tua/kegiatan', [
            'anak' => [
                'id' => $siswa->id,
                'nama' => $siswa->nama,
                'kelas' => $siswa->kelasTahunAktif?->kelas?->nama_lengkap,
                'diskon_anak_guru' => $siswa->berhakDiskonAnakGuru(),
            ],
            'wajib' => $this->service->kegiatanWajib($siswa)->map(fn (Ekstrakurikuler $e) => [
                'nama' => $e->nama,
                'jadwal' => $jenjang ? $this->jadwal($e, $jenjang) : [],
            ]),
            'diikuti' => $aktif->map(fn (Pendaftaran $p) => [
                'id' => $p->id,
                'nama' => $p->ekstrakurikuler?->induk ? $p->ekstrakurikuler->induk->nama : $p->nama_kegiatan,
                'kelas_paralel' => $p->ekstrakurikuler?->induk ? $p->ekstrakurikuler->nama : null,
                'jadwal' => $p->ekstrakurikuler && $jenjang ? $this->jadwal($p->ekstrakurikuler, $jenjang) : [],
                'tanggal_daftar' => $p->tanggal_daftar->toDateString(),
            ])->values(),
            'pilihan' => $buka && $jenjang
                ? $this->service->pilihanKegiatan($siswa)
                    ->reject(fn (LesTambahan|Ekstrakurikuler $k) => $diikuti->contains(($k instanceof LesTambahan ? 'les-' : 'ekskul-').$k->id))
                    ->map(fn (LesTambahan|Ekstrakurikuler $k) => $this->pilihan($siswa, $k, $jenjang))
                    ->values()
                : [],
            'periode' => $this->ringkasPeriode($buka),
            'periodeBerikutnya' => $this->ringkasPeriode($buka ? null : $this->service->periodeBerikutnya()),
        ]);
    }

    public function daftar(Request $request, Siswa $siswa): RedirectResponse
    {
        $this->pastikanAnakSendiri($request, $siswa);
        $data = $request->validate([
            'jenis' => ['required', Rule::enum(TipeKegiatan::class)],
            'kegiatan_id' => ['required', 'integer'],
        ]);

        $kegiatan = $data['jenis'] === TipeKegiatan::LesTambahan->value
            ? LesTambahan::findOrFail($data['kegiatan_id'])
            : Ekstrakurikuler::findOrFail($data['kegiatan_id']);
        $pendaftaran = $this->service->daftarOlehOrangTua($request->user(), $siswa, $kegiatan);

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$siswa->nama} terdaftar di {$pendaftaran->nama_kegiatan}. Tagihan dibuat otomatis."]);

        return back();
    }

    // Orang tua hanya membuka anak yang diklaimnya sendiri; anak lain dianggap tidak ada
    private function pastikanAnakSendiri(Request $request, Siswa $siswa): void
    {
        abort_unless($request->user()->anak()->whereKey($siswa->id)->exists(), 404);
    }

    private function pilihan(Siswa $siswa, LesTambahan|Ekstrakurikuler $kegiatan, int $jenjang): array
    {
        $hasil = $this->service->periksa($siswa, $kegiatan);
        $ekskul = $kegiatan instanceof Ekstrakurikuler;

        return [
            'jenis' => $ekskul ? TipeKegiatan::Ekstrakurikuler : TipeKegiatan::LesTambahan,
            'id' => $kegiatan->id,
            'nama' => $ekskul ? $kegiatan->nama : 'Les Tambahan '.$kegiatan->kelas->nama_lengkap,
            'biaya' => (int) $kegiatan->biaya,
            'jadwal' => $ekskul ? $this->jadwal($kegiatan, $jenjang) : [],
            'kuota' => $ekskul ? $kegiatan->kuota : null,
            'terisi' => $ekskul ? $this->service->terisi($kegiatan) : null,
            'punya_kelas_paralel' => $ekskul && $kegiatan->kelasParalel->isNotEmpty(),
            // Untuk orang tua kuota penuh = tidak bisa dipilih (FR-4)
            'alasan_tidak_bisa' => $hasil['error'] ?? ($hasil['peringatan'] ? 'Kuota sudah penuh.' : null),
        ];
    }

    private function jadwal(Ekstrakurikuler $ekskul, int $jenjang): array
    {
        return $this->service->jadwalUntuk($ekskul, $jenjang)
            ->map(fn (JadwalEkskul $j) => $this->service->teksJadwal($j).($j->lokasi ? ' · '.$j->lokasi : ''))
            ->values()
            ->all();
    }

    private function ringkasPeriode(?PeriodePendaftaran $periode): ?array
    {
        return $periode ? ['tanggal_mulai' => $periode->tanggal_mulai->toDateString(), 'tanggal_selesai' => $periode->tanggal_selesai->toDateString()] : null;
    }
}
