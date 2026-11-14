<?php

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard'); // beranda tugas sesuai role (PRD Ringkasan v1.11 #32)
});

require __DIR__.'/settings.php';
require __DIR__.'/les-ekskul.php';
require __DIR__.'/jurnal-guru.php';
require __DIR__.'/jadwal-guru.php';
