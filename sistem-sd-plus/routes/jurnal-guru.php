<?php

use Illuminate\Support\Facades\Route;

// Modul Jurnal Guru (Pengembang 1) — PRD B.2.
// Dipakai guru dan Kepala Sekolah dengan hak berbeda, jadi role dipasang per sub-grup.
Route::middleware(['auth'])
    ->prefix('jurnal-guru')
    ->name('jurnal-guru.')
    ->group(function () {
        //
    });
