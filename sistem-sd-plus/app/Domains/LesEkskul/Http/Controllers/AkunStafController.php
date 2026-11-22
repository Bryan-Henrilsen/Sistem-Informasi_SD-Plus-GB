<?php

namespace App\Domains\LesEkskul\Http\Controllers;

use App\Domains\LesEkskul\Services\AkunStafService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

// Akun Guru & Staf — khusus Kepala Sekolah (Ringkasan v1.11 #46)
class AkunStafController extends Controller
{
    public function __construct(private AkunStafService $service) {}

    public function index(Request $request): Response
    {
        return Inertia::render('les-ekskul/akun-staf', [
            'akun' => $this->service->daftar(),
            'saya' => $request->user()->id,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        [$user, $password] = $this->service->tambah($this->validasi($request));

        // Password awal ditampilkan sekali lewat flash; tidak disimpan di tempat lain
        Inertia::flash('password', ['nama' => $user->name, 'email' => $user->email, 'password' => $password]);

        return $this->kembali("Akun {$user->name} dibuat.");
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $this->service->ubah($user, $this->validasi($request), $request->user());

        return $this->kembali("Akun {$user->name} disimpan.");
    }

    public function resetPassword(User $user): RedirectResponse
    {
        $password = $this->service->resetPassword($user);
        Inertia::flash('password', ['nama' => $user->name, 'email' => $user->email, 'password' => $password]);

        return $this->kembali("Password {$user->name} direset.");
    }

    public function nonaktifkan(Request $request, User $user): RedirectResponse
    {
        $peringatan = $this->service->nonaktifkan($user, $request->user());

        Inertia::flash('toast', [
            'type' => $peringatan === [] ? 'success' : 'warning',
            'message' => trim("Akun {$user->name} dinonaktifkan dan tidak bisa login lagi. ".implode(' ', $peringatan)),
        ]);

        return back();
    }

    public function aktifkan(User $user): RedirectResponse
    {
        $this->service->aktifkan($user);

        return $this->kembali("Akun {$user->name} aktif kembali.");
    }

    private function validasi(Request $request): array
    {
        return $request->validate([
            'nama' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'peran' => ['array'],
            'peran.*' => ['string'],
            'kode_guru' => ['nullable', 'string', 'max:10'],
            'nuptk' => ['nullable', 'string', 'max:30'],
        ], ['nama.required' => 'Nama wajib diisi.', 'email.required' => 'Email wajib diisi.', 'email.email' => 'Format email tidak valid.']);
    }

    private function kembali(string $pesan): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $pesan]);

        return back();
    }
}
