<?php

use Illuminate\Support\Facades\Route;

// Modul Les & Ekstrakurikuler (Pengembang 1) — PRD B.1.
// Pemakainya campur (TU, Bendahara, Kepala Sekolah, orang tua), jadi role dipasang per sub-grup, bukan di sini.
Route::middleware(['auth'])
    ->prefix('les-ekskul')
    ->name('les-ekskul.')
    ->group(function () {
        //
    });
