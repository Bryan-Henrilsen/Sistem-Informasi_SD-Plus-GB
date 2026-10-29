<?php

use Illuminate\Support\Facades\Route;

// Modul Jadwal Guru (Pengembang 2) — PRD B.3.
// Dikelola Waka Kurikulum; Kepala Sekolah juga boleh mengedit (v1.10).
Route::middleware(['auth', 'role:waka_kurikulum|kepala_sekolah'])
    ->prefix('jadwal-guru')
    ->name('jadwal-guru.')
    ->group(function () {
        //
    });
