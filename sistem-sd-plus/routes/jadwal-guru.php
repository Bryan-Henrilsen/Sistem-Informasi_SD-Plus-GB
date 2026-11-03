<?php

use Illuminate\Support\Facades\Route;
use App\Domains\JadwalGuru\Http\Controllers\PembagianJadwalController;
use App\Domains\JadwalGuru\Http\Controllers\KisiKisiTugasController;

/*
|--------------------------------------------------------------------------
| Routes Modul Jadwal Guru
|--------------------------------------------------------------------------
| Hanya bisa diakses oleh Waka Kurikulum dan Kepala Sekolah
*/

/*Route::middleware(['auth', 'role:waka_kurikulum|kepala_sekolah'])->prefix('jadwal-guru')->name('jadwal-guru.')->group(function () {*/
    Route::prefix('jadwal-guru')->name('jadwal-guru.')->group(function () {
    // Kisi-Kisi Tugas
    Route::get('/kisi-kisi', [KisiKisiTugasController::class, 'index'])->name('kisi-kisi.index');
    Route::post('/kisi-kisi', [KisiKisiTugasController::class, 'store'])->name('kisi-kisi.store');
    Route::put('/kisi-kisi/{id}', [KisiKisiTugasController::class, 'update'])->name('kisi-kisi.update');
    Route::delete('/kisi-kisi/{id}', [KisiKisiTugasController::class, 'destroy'])->name('kisi-kisi.destroy');

    // Pembagian Jadwal (route yang sudah ada, dipertahankan)
    Route::get('/pembagian', [PembagianJadwalController::class, 'index'])->name('pembagian.index');
    Route::post('/pembagian/assign', [PembagianJadwalController::class, 'store'])->name('pembagian.store');
    Route::delete('/pembagian/{id}', [PembagianJadwalController::class, 'destroy'])->name('pembagian.destroy');
});
