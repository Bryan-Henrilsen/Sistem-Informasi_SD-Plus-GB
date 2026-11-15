<?php

namespace App\Domains\LesEkskul\Http\Controllers;

use App\Domains\LesEkskul\Enums\StatusPendaftaran;
use App\Domains\LesEkskul\Models\Pendaftaran;
use App\Domains\LesEkskul\Services\AkunOrangTuaService;
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

    public function beranda(Request $request): Response
    {
        return Inertia::render('les-ekskul/orang-tua/beranda', [
            'anak' => $this->anak($request->user()),
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
