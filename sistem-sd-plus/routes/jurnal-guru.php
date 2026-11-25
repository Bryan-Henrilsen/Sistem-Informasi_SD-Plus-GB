<?php

use App\Domains\JurnalGuru\Http\Controllers\ForumController;
use App\Domains\JurnalGuru\Http\Controllers\JadwalSayaController;
use Illuminate\Support\Facades\Route;

// Modul Jurnal Guru (Pengembang 1) — PRD B.2.
// Dipakai guru dan Kepala Sekolah dengan hak berbeda, jadi role dipasang per sub-grup.
Route::middleware(['auth'])
    ->prefix('jurnal-guru')
    ->name('jurnal-guru.')
    ->group(function () {
        // Forum privat guru ↔ Kepala Sekolah (FR-2–FR-4); siapa membuka thread siapa dijaga ForumService
        Route::middleware('role:guru|kepala_sekolah')->group(function () {
            Route::get('forum', [ForumController::class, 'index'])->name('forum.index');
            Route::get('forum/{guru}', [ForumController::class, 'show'])->name('forum.show');
            Route::post('forum/{guru}', [ForumController::class, 'store'])->name('forum.store');
            Route::delete('forum/pesan/{forumPesan}', [ForumController::class, 'destroy'])->name('forum.destroy');
        });

        // Jadwal mengajar pribadi guru (FR-1)
        Route::get('jadwal-saya', JadwalSayaController::class)->middleware('role:guru')->name('jadwal-saya');
    });
