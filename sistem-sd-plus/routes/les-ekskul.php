<?php

use App\Domains\LesEkskul\Http\Controllers\LaporanController;
use App\Domains\LesEkskul\Http\Controllers\PendaftaranController;
use App\Domains\LesEkskul\Http\Controllers\PeriodeLaporanController;
use App\Domains\LesEkskul\Http\Controllers\RefundController;
use App\Domains\LesEkskul\Http\Controllers\TagihanController;
use App\Domains\LesEkskul\Http\Controllers\TransaksiController;
use Illuminate\Support\Facades\Route;

// Modul Les & Ekstrakurikuler (Pengembang 1) — PRD B.1.
// Pemakainya campur (TU, Bendahara, Kepala Sekolah, orang tua), jadi role dipasang per sub-grup, bukan di sini.
Route::middleware(['auth'])
    ->prefix('les-ekskul')
    ->name('les-ekskul.')
    ->group(function () {
        // Keuangan — Kepala Sekolah ikut melihat (A3)
        Route::middleware('role:tu|bendahara|kepala_sekolah')->group(function () {
            Route::get('periode', [PeriodeLaporanController::class, 'index'])->name('periode.index');
            Route::get('tagihan', [TagihanController::class, 'index'])->name('tagihan.index');
            Route::get('transaksi', [TransaksiController::class, 'index'])->name('transaksi.index');
            Route::get('pendaftaran', [PendaftaranController::class, 'index'])->name('pendaftaran.index');
            Route::get('refund', [RefundController::class, 'index'])->name('refund.index');
            Route::get('laporan-harian', [LaporanController::class, 'harian'])->name('laporan-harian.index');
            Route::get('periode/{periode}', [LaporanController::class, 'bulanan'])->name('periode.show');
        });

        // Laporan harian & bulanan: TU mengajukan / menarik kembali (FR-12, FR-14; #15, #31)
        Route::middleware('role:tu')->group(function () {
            Route::post('laporan-harian/{laporanHarian}/ajukan', [LaporanController::class, 'ajukanHarian'])->name('laporan-harian.ajukan');
            Route::post('laporan-harian/{laporanHarian}/tarik', [LaporanController::class, 'tarikHarian'])->name('laporan-harian.tarik');
            Route::post('periode/{periode}/ajukan', [LaporanController::class, 'ajukanBulanan'])->name('periode.ajukan');
            Route::post('periode/{periode}/tarik', [LaporanController::class, 'tarikBulanan'])->name('periode.tarik');
        });

        // Bendahara menyetujui / menolak laporan harian, mengembalikan laporan bulanan, dan menutup buku (FR-12, FR-14, FR-14b)
        Route::middleware('role:bendahara')->group(function () {
            Route::post('laporan-harian/{laporanHarian}/setujui', [LaporanController::class, 'setujuiHarian'])->name('laporan-harian.setujui');
            Route::post('laporan-harian/{laporanHarian}/tolak', [LaporanController::class, 'tolakHarian'])->name('laporan-harian.tolak');
            Route::post('periode/{periode}/kembalikan', [LaporanController::class, 'kembalikanBulanan'])->name('periode.kembalikan');
            Route::post('periode/{periode}/tutup-buku', [LaporanController::class, 'tutupBuku'])->name('periode.tutup-buku');
        });

        // Pendaftaran, berhenti/pindah, pembebasan wajib ekskul, dan refund — TU (FR-6, FR-10, FR-11, FR-22, FR-27; #29–#30)
        Route::middleware('role:tu')->group(function () {
            Route::get('pendaftaran/tambah', [PendaftaranController::class, 'create'])->name('pendaftaran.create');
            Route::post('pendaftaran', [PendaftaranController::class, 'store'])->name('pendaftaran.store');
            Route::post('pendaftaran/{pendaftaran}/assign', [PendaftaranController::class, 'assign'])->name('pendaftaran.assign');
            Route::post('pendaftaran/{pendaftaran}/berhenti', [PendaftaranController::class, 'berhenti'])->name('pendaftaran.berhenti');
            Route::post('pendaftaran/{pendaftaran}/pindah', [PendaftaranController::class, 'pindah'])->name('pendaftaran.pindah');
            Route::post('siswa-kelas/{siswaKelas}/bebas-ekskul', [PendaftaranController::class, 'bebaskan'])->name('pendaftaran.bebaskan');
            Route::delete('siswa-kelas/{siswaKelas}/bebas-ekskul', [PendaftaranController::class, 'cabutBebas'])->name('pendaftaran.cabut-bebas');
            Route::post('refund/{refund}/dikembalikan', [RefundController::class, 'tandai'])->name('refund.tandai');
        });

        // Mengubah data keuangan — TU dan Bendahara (Ringkasan v1.11 #22–#26)
        Route::middleware('role:tu|bendahara')->group(function () {
            Route::post('periode', [PeriodeLaporanController::class, 'store'])->name('periode.store');
            Route::post('tagihan/bayar-di-muka', [TagihanController::class, 'bayarDiMuka'])->name('tagihan.bayar-di-muka');
            Route::get('transaksi/catat', [TransaksiController::class, 'create'])->name('transaksi.create');
            Route::post('transaksi', [TransaksiController::class, 'store'])->name('transaksi.store');
            Route::post('transaksi/{transaksi}/tolak', [TransaksiController::class, 'tolak'])->name('transaksi.tolak');
        });

        Route::post('transaksi/{transaksi}/verifikasi', [TransaksiController::class, 'verifikasi'])
            ->middleware('role:tu')->name('transaksi.verifikasi'); // FR-8
        Route::post('transaksi/{transaksi}/setujui', [TransaksiController::class, 'setujui'])
            ->middleware('role:bendahara')->name('transaksi.setujui'); // FR-8b
    });
