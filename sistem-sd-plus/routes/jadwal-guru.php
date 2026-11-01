<?php

use Illuminate\Support\Facades\Route;
use App\Domains\JadwalGuru\Http\Controllers\PembagianJadwalController;

/*
|--------------------------------------------------------------------------
| Routes Modul Jadwal Guru
|--------------------------------------------------------------------------
| Hanya bisa diakses oleh Waka Kurikulum dan Kepala Sekolah
*/

/*Route::middleware(['auth', 'role:waka_kurikulum|kepala_sekolah'])->prefix('jadwal-guru')->name('jadwal-guru.')->group(function () {*/
    Route::prefix('jadwal-guru')->name('jadwal-guru.')->group(function () {
    // Fitur Inti Pembagian Jadwal (Drag & Drop)
    Route::get('/pembagian', [PembagianJadwalController::class, 'index'])->name('pembagian.index');
    Route::post('/pembagian/assign', [PembagianJadwalController::class, 'store'])->name('pembagian.store');
    Route::delete('/pembagian/{id}', [PembagianJadwalController::class, 'destroy'])->name('pembagian.destroy');
    
});