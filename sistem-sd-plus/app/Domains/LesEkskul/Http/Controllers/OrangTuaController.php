<?php

namespace App\Domains\LesEkskul\Http\Controllers;

use App\Domains\LesEkskul\Enums\StatusPendaftaran;
use App\Domains\LesEkskul\Models\Pendaftaran;
use App\Domains\LesEkskul\Services\AkunOrangTuaService;
use App\Domains\LesEkskul\Services\PendaftaranService;
use App\Domains\LesEkskul\Services\TagihanOrangTuaService;
use App\Http\Controllers\Controller;
use App\Models\Kelas;
use App\Models\Siswa;
use App\Models\SiswaKelas;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

// Halaman orang tua (layout khusus HP): beranda, anak saya & klaim, akun (FR-1, FR-1b; Ringkasan v1.11 #36)
class OrangTuaController extends Controller
{
    public function __construct(private AkunOrangTuaService $service) {}

    public function beranda(Request $request, PendaftaranService $pendaftaran, TagihanOrangTuaService $tagihan): Response
    {
        $buka = $pendaftaran->periodeBuka();

        return Inertia::render('les-ekskul/orang-tua/beranda', [
            // Tiap anak dilengkapi tunggakan & sisa bulan ini (#38)
            'anak' => array_map(
                fn (array $a) => $a + $tagihan->ringkasan($tagihan->tagihan(Siswa::findOrFail($a['id']))),
                $this->anak($request->user()),
            ),
            // Pemberitahuan periode pendaftaran yang sedang dibuka (#37)
            'periodeBuka' => $buka ? ['tanggal_mulai' => $buka->tanggal_mulai->toDateString(), 'tanggal_selesai' => $buka->tanggal_selesai->toDateString()] : null,
        ]);
    }

    public function anakSaya(Request $request): Response
    {
        $diklaim = $request->user()->anak()->pluck('siswa.id');

        return Inertia::render('les-ekskul/orang-tua/anak', [
            'anak' => $this->anak($request->user()),
            'kelas' => $this->service->kelasUntukKlaim()->map(fn (Kelas $k) => [
                'id' => $k->id,
                'nama' => $k->nama_lengkap,
                'siswa' => $k->siswaKelas
                    ->map(fn (SiswaKelas $sk) => ['id' => $sk->siswa->id, 'nama' => $sk->siswa->nama, 'diklaim' => $diklaim->contains($sk->siswa->id)])
                    ->sortBy('nama', SORT_NATURAL | SORT_FLAG_CASE)
                    ->values(),
            ]),
        ]);
    }

    public function klaim(Request $request): RedirectResponse
    {
        $data = $request->validate(['siswa_id' => ['required', 'integer', 'exists:siswa,id']], ['siswa_id.required' => 'Pilih nama anak.']);
        $siswa = Siswa::findOrFail($data['siswa_id']);
        $this->service->klaim($request->user(), $siswa);

        return $this->kembali("{$siswa->nama} ditambahkan ke daftar anak Anda.");
    }

    public function lepas(Request $request, Siswa $siswa): RedirectResponse
    {
        $this->service->lepas($request->user(), $siswa);

        return $this->kembali("{$siswa->nama} dihapus dari daftar anak Anda.");
    }

    // Tagihan & status pembayaran semua anak, dengan info rekening transfer manual (FR-15, FR-32; #38)
    public function tagihan(Request $request, TagihanOrangTuaService $tagihan): Response
    {
        return Inertia::render('les-ekskul/orang-tua/tagihan', [
            'anak' => $request->user()->anak()->with('kelasTahunAktif.kelas')->orderBy('nama')->get()
                ->map(function (Siswa $s) use ($tagihan) {
                    $daftar = $tagihan->tagihan($s);

                    return [
                        'id' => $s->id,
                        'nama' => $s->nama,
                        'kelas' => $s->kelasTahunAktif?->kelas?->nama_lengkap,
                        'ringkasan' => $tagihan->ringkasan($daftar),
                        'tagihan' => $daftar->values(),
                        'ditolak' => $tagihan->transferDitolak($s)->values(),
                    ];
                })
                ->values(),
            'rekening' => $tagihan->rekening(),
            'waTu' => config('les_ekskul.wa_tu'),
        ]);
    }

    public function akun(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('les-ekskul/orang-tua/akun', [
            'profil' => ['name' => $user->name, 'no_hp' => $user->no_hp, 'email' => $user->email],
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]);
    }

    // Anak yang diklaim beserta kelas tahun aktif dan kegiatan aktifnya
    private function anak(User $orangTua): array
    {
        return $orangTua->anak()
            ->with(['kelasTahunAktif.kelas', 'pendaftaran' => fn ($q) => $q->where('status', StatusPendaftaran::Aktif)->with(['lesTambahan', 'ekstrakurikuler'])])
            ->orderBy('nama')
            ->get()
            ->map(fn (Siswa $s) => [
                'id' => $s->id,
                'nama' => $s->nama,
                'kelas' => $s->kelasTahunAktif?->kelas?->nama_lengkap,
                'kegiatan' => $s->pendaftaran
                    ->map(fn (Pendaftaran $p) => $p->lesTambahan ? 'Les Tambahan' : $p->ekstrakurikuler?->nama)
                    ->filter()
                    ->values(),
            ])
            ->all();
    }

    private function kembali(string $pesan): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $pesan]);

        return back();
    }
}
