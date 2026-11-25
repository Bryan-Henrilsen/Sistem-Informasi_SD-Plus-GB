<?php

namespace App\Http\Controllers;

use App\Domains\JurnalGuru\Services\ForumService;
use App\Domains\LesEkskul\Services\RingkasanTugasService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

// Beranda "Tugas Saya": kartu tugas sesuai role pengguna (Ringkasan v1.11 #32).
// Modul lain (Jadwal Guru, Jurnal Guru) dapat menambah bagiannya sendiri di sini.
class DashboardController extends Controller
{
    public function __invoke(Request $request, RingkasanTugasService $ringkasan): Response|RedirectResponse
    {
        $user = $request->user();

        // Akun yang hanya orang tua memakai beranda khusus orang tua (#36); guru yang juga orang tua tetap di sini
        if ($user->hasRole('orang_tua') && $user->roles()->count() === 1) {
            return redirect()->route('ortu.beranda');
        }

        $keuangan = $user->hasAnyRole(RingkasanTugasService::ROLE_KEUANGAN);

        return Inertia::render('dashboard', [
            'tugas' => [
                ...($keuangan ? $ringkasan->kartuTugas($user) : []),
                ...app(ForumService::class)->kartuTugas($user), // Jurnal Guru: pesan forum belum dibaca (#51)
            ],
            'keuangan' => $keuangan ? $ringkasan->ringkasanKeuangan() : null,
        ]);
    }
}
