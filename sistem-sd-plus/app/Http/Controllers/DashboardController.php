<?php

namespace App\Http\Controllers;

use App\Domains\LesEkskul\Services\RingkasanTugasService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

// Beranda "Tugas Saya": kartu tugas sesuai role pengguna (Ringkasan v1.11 #32).
// Modul lain (Jadwal Guru, Jurnal Guru) dapat menambah bagiannya sendiri di sini.
class DashboardController extends Controller
{
    public function __invoke(Request $request, RingkasanTugasService $ringkasan): Response
    {
        $user = $request->user();
        $keuangan = $user->hasAnyRole(RingkasanTugasService::ROLE_KEUANGAN);

        return Inertia::render('dashboard', [
            'tugas' => $keuangan ? $ringkasan->kartuTugas($user) : [],
            'keuangan' => $keuangan ? $ringkasan->ringkasanKeuangan() : null,
        ]);
    }
}
