<?php

use Illuminate\Support\Facades\Route;
use App\Domains\JadwalGuru\Http\Controllers\KisiKisiTugasController;
use App\Domains\JadwalGuru\Http\Controllers\PembagianJadwalController;
use App\Domains\JadwalGuru\Http\Controllers\WaktuPelajaranController;

/*
|--------------------------------------------------------------------------
| Routes Modul Jadwal Guru
|--------------------------------------------------------------------------
| Middleware auth dan role mengikuti konfigurasi development project.
*/

Route::prefix('jadwal-guru')->name('jadwal-guru.')->group(function () {
    // Waktu Pelajaran
    Route::get('/waktu-pelajaran', [WaktuPelajaranController::class, 'index'])->name('waktu-pelajaran.index');
    Route::post('/waktu-pelajaran', [WaktuPelajaranController::class, 'store'])->name('waktu-pelajaran.store');
    Route::put('/waktu-pelajaran/{id}', [WaktuPelajaranController::class, 'update'])->name('waktu-pelajaran.update');
    Route::delete('/waktu-pelajaran/{id}', [WaktuPelajaranController::class, 'destroy'])->name('waktu-pelajaran.destroy');

    // Kisi-Kisi Tugas
    Route::get('/kisi-kisi', [KisiKisiTugasController::class, 'index'])->name('kisi-kisi.index');
    Route::post('/kisi-kisi', [KisiKisiTugasController::class, 'store'])->name('kisi-kisi.store');
    Route::put('/kisi-kisi/{id}', [KisiKisiTugasController::class, 'update'])->name('kisi-kisi.update');
    Route::delete('/kisi-kisi/{id}', [KisiKisiTugasController::class, 'destroy'])->name('kisi-kisi.destroy');

    // Pembagian Jadwal
    Route::get('/pembagian', [PembagianJadwalController::class, 'index'])->name('pembagian.index');
    Route::post('/pembagian/assign', [PembagianJadwalController::class, 'store'])->name('pembagian.store');
    Route::delete('/pembagian/{id}', [PembagianJadwalController::class, 'destroy'])->name('pembagian.destroy');
});
