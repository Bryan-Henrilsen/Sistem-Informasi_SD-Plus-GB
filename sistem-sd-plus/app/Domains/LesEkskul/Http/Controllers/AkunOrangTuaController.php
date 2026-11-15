<?php

namespace App\Domains\LesEkskul\Http\Controllers;

use App\Domains\LesEkskul\Services\AkunOrangTuaService;
use App\Http\Controllers\Controller;
use App\Models\Kelas;
use App\Models\Siswa;
use App\Models\TahunAjaran;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

// Halaman Akun Orang Tua untuk TU: lihat & hapus klaim, reset password, tambah siswa yang belum ada (FR-1b, #36)
class AkunOrangTuaController extends Controller
{
    public function __construct(private AkunOrangTuaService $service) {}

    public function index(Request $request): Response
    {
        $cari = trim((string) $request->input('cari'));
        // Potongan no HP: "+62 812" dicari sebagai "0812", sama dengan bentuk yang disimpan
        $digit = preg_replace('/\D+/', '', $cari);
        $digit = str_starts_with($digit, '62') ? '0'.substr($digit, 2) : $digit;

        $orangTua = User::role('orang_tua')
            ->when($cari !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('name', 'ilike', "%{$cari}%")
                ->when(strlen($digit) >= 4, fn (Builder $h) => $h->orWhere('no_hp', 'like', "%{$digit}%"))
                ->orWhereHas('anak', fn (Builder $a) => $a->where('nama', 'ilike', "%{$cari}%"))))
            ->with(['anak.kelasTahunAktif.kelas'])
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (User $u) => [
                'id' => $u->id,
                'nama' => $u->name,
                'no_hp' => $u->no_hp,
                'email' => $u->email,
                'terdaftar' => $u->created_at?->toDateString(),
                'anak' => $u->anak->sortBy('nama')->values()->map(fn (Siswa $s) => [
                    'id' => $s->id,
                    'nama' => $s->nama,
                    'kelas' => $s->kelasTahunAktif?->kelas?->nama_lengkap,
                    'diklaim' => $s->pivot->created_at?->toDateString(),
                ]),
            ]);

        return Inertia::render('les-ekskul/akun-orang-tua', [
            'orangTua' => $orangTua,
            'cari' => $cari,
            'kelas' => Kelas::where('tahun_ajaran_id', TahunAjaran::aktif()->value('id'))
                ->orderBy('jenjang')->orderBy('nama_kelas')->get()
                ->map(fn (Kelas $k) => ['id' => $k->id, 'nama' => $k->nama_lengkap]),
            'passwordBaru' => $request->session()->get('password_baru'),
        ]);
    }

    public function hapusKlaim(User $user, Siswa $siswa): RedirectResponse
    {
        $this->service->lepas($user, $siswa);

        return $this->kembali("Klaim {$user->name} atas {$siswa->nama} dihapus.");
    }

    // Password sementara ditampilkan sekali lewat session flash, tidak masuk toast atau log
    public function resetPassword(User $user): RedirectResponse
    {
        $password = $this->service->resetPassword($user);

        return back()->with('password_baru', ['nama' => $user->name, 'no_hp' => $user->no_hp, 'password' => $password]);
    }

    public function tambahSiswa(Request $request): RedirectResponse
    {
        $tahunAjaranId = TahunAjaran::aktif()->value('id');
        $data = $request->validate([
            'nama' => ['required', 'string', 'max:255'],
            'kelas_id' => ['required', Rule::exists('kelas', 'id')->where('tahun_ajaran_id', $tahunAjaranId)],
            'jenis_kelamin' => ['nullable', Rule::in(['L', 'P'])],
        ], ['nama.required' => 'Nama wajib diisi.', 'kelas_id.required' => 'Pilih kelas.']);

        $kelas = Kelas::findOrFail($data['kelas_id']);
        $siswa = $this->service->tambahSiswa($data['nama'], $kelas, $data['jenis_kelamin'] ?? null);

        return $this->kembali("{$siswa->nama} ditambahkan ke kelas {$kelas->nama_lengkap}; orang tua sudah bisa mengklaimnya.");
    }

    private function kembali(string $pesan): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $pesan]);

        return back();
    }
}
