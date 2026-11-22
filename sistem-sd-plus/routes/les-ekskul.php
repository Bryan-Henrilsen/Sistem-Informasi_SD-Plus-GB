<?php

use App\Domains\LesEkskul\Http\Controllers\AkunOrangTuaController;
use App\Domains\LesEkskul\Http\Controllers\KegiatanAnakController;
use App\Domains\LesEkskul\Http\Controllers\KonfirmasiTransferController;
use App\Domains\LesEkskul\Http\Controllers\LaporanController;
use App\Domains\LesEkskul\Http\Controllers\LaporanKasController;
use App\Domains\LesEkskul\Http\Controllers\OrangTuaController;
use App\Domains\LesEkskul\Http\Controllers\PendaftaranController;
use App\Domains\LesEkskul\Http\Controllers\PengajarEkskulController;
use App\Domains\LesEkskul\Http\Controllers\PeriodeLaporanController;
use App\Domains\LesEkskul\Http\Controllers\RefundController;
use App\Domains\LesEkskul\Http\Controllers\RekapHonorController;
use App\Domains\LesEkskul\Http\Controllers\SkemaHonorController;
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
            Route::get('honor/pengajar', [PengajarEkskulController::class, 'index'])->name('honor.pengajar');
            Route::get('honor/skema', [SkemaHonorController::class, 'index'])->name('honor.skema');
            Route::get('honor/rekap', [RekapHonorController::class, 'index'])->name('honor.rekap');
            Route::get('laporan-kas', [LaporanKasController::class, 'index'])->name('laporan-kas.index');
        });

        // Pengaturan honor oleh TU & Kepala Sekolah: data pengajar, penugasan, cara hitung, penerima tanpa akun (#33)
        Route::middleware('role:tu|kepala_sekolah')->group(function () {
            Route::post('honor/pengajar', [PengajarEkskulController::class, 'store'])->name('honor.pengajar.store');
            Route::put('honor/pengajar/{pengajarEkskul}', [PengajarEkskulController::class, 'update'])->name('honor.pengajar.update');
            Route::post('honor/kegiatan/{ekstrakurikuler}/mode', [PengajarEkskulController::class, 'aturMode'])->name('honor.kegiatan.mode');
            Route::post('honor/kegiatan/{ekstrakurikuler}/pengajar', [PengajarEkskulController::class, 'aturPengajar'])->name('honor.kegiatan.pengajar');
            Route::post('honor/penerima-tanpa-akun', [SkemaHonorController::class, 'tambahTanpaAkun'])->name('honor.tanpa-akun');
        });

        // Pengaturan honor khusus Kepala Sekolah: pendamping & kategori (FR-18), skema persen (FR-17), penerima peran, honor flat (FR-25b)
        Route::middleware('role:kepala_sekolah')->group(function () {
            Route::post('honor/kegiatan/{ekstrakurikuler}/kategori', [PengajarEkskulController::class, 'aturKategori'])->name('honor.kegiatan.kategori');
            Route::post('honor/kegiatan/{ekstrakurikuler}/pendamping', [PengajarEkskulController::class, 'aturPendamping'])->name('honor.kegiatan.pendamping');
            Route::post('honor/skema', [SkemaHonorController::class, 'simpanSkema'])->name('honor.skema.simpan');
            Route::post('honor/peran', [SkemaHonorController::class, 'simpanPeran'])->name('honor.peran.simpan');
            Route::post('honor/flat/{honorFlatJenis}', [SkemaHonorController::class, 'simpanHonorFlat'])->name('honor.flat.simpan');
        });

        // Hitung / hitung ulang honor oleh Bendahara & Kepala Sekolah; "Sudah Diketahui" hanya Kepala Sekolah (FR-20, #34)
        Route::post('honor/rekap/{periode}/hitung', [RekapHonorController::class, 'hitung'])
            ->middleware('role:bendahara|kepala_sekolah')->name('honor.rekap.hitung');
        Route::post('honor/rekap/{periode}/diketahui', [RekapHonorController::class, 'tandaiDiketahui'])
            ->middleware('role:kepala_sekolah')->name('honor.rekap.diketahui');
        // Honor flat per periode: dibayar atau tidak, penerima & jumlahnya (#39)
        Route::post('honor/rekap/{periode}/flat/{honorFlatJenis}', [RekapHonorController::class, 'aturFlat'])
            ->middleware('role:kepala_sekolah')->name('honor.rekap.flat');

        // Catatan kas manual (saldo awal, kolam, ATK, admin bank, setor yayasan, lainnya) oleh Bendahara & Kepala Sekolah (#35)
        Route::middleware('role:bendahara|kepala_sekolah')->group(function () {
            Route::post('laporan-kas/{periode}', [LaporanKasController::class, 'store'])->name('laporan-kas.store');
            Route::delete('kas-manual/{kasManual}', [LaporanKasController::class, 'destroy'])->name('laporan-kas.destroy');
        });

        // Laporan harian & bulanan: TU mengajukan / menarik kembali (FR-12, FR-14; #15, #31)
        Route::middleware('role:tu')->group(function () {
            Route::post('laporan-harian/ajukan-semua', [LaporanController::class, 'ajukanSemuaHarian'])->name('laporan-harian.ajukan-semua');
            Route::post('laporan-harian/{laporanHarian}/ajukan', [LaporanController::class, 'ajukanHarian'])->name('laporan-harian.ajukan');
            Route::post('laporan-harian/{laporanHarian}/tarik', [LaporanController::class, 'tarikHarian'])->name('laporan-harian.tarik');
            Route::post('periode/{periode}/ajukan', [LaporanController::class, 'ajukanBulanan'])->name('periode.ajukan');
            Route::post('periode/{periode}/tarik', [LaporanController::class, 'tarikBulanan'])->name('periode.tarik');
        });

        // Bendahara menyetujui / menolak laporan harian, mengembalikan laporan bulanan, dan menutup buku (FR-12, FR-14, FR-14b)
        Route::middleware('role:bendahara')->group(function () {
            Route::post('laporan-harian/{laporanHarian}/setujui', [LaporanController::class, 'setujuiHarian'])->name('laporan-harian.setujui');
            Route::post('laporan-harian/{laporanHarian}/tolak', [LaporanController::class, 'tolakHarian'])->name('laporan-harian.tolak');
            Route::post('laporan-harian/{laporanHarian}/setujui-transfer', [LaporanController::class, 'setujuiTransferHarian'])->name('laporan-harian.setujui-transfer');
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
        // Konfirmasi "Saya sudah bayar" dari orang tua: TU menolak bila transfer tidak ditemukan (FR-15b, #41)
        Route::post('konfirmasi-transfer/{konfirmasiTransfer}/tolak', [KonfirmasiTransferController::class, 'tolak'])
            ->middleware('role:tu')->name('konfirmasi-transfer.tolak');

        // Akun orang tua oleh TU: hapus klaim salah, reset password, tambah siswa yang belum ada (FR-1b, #36)
        Route::middleware('role:tu')->group(function () {
            Route::get('akun-orang-tua', [AkunOrangTuaController::class, 'index'])->name('akun-orang-tua.index');
            Route::delete('akun-orang-tua/{user}/anak/{siswa}', [AkunOrangTuaController::class, 'hapusKlaim'])->name('akun-orang-tua.hapus-klaim');
            Route::post('akun-orang-tua/{user}/reset-password', [AkunOrangTuaController::class, 'resetPassword'])->name('akun-orang-tua.reset-password');
            Route::post('siswa', [AkunOrangTuaController::class, 'tambahSiswa'])->name('siswa.store');

            // Jendela pendaftaran oleh orang tua (FR-2, #37)
            Route::post('periode-pendaftaran', [PendaftaranController::class, 'simpanPeriode'])->name('periode-pendaftaran.store');
            Route::delete('periode-pendaftaran/{periodePendaftaran}', [PendaftaranController::class, 'hapusPeriode'])->name('periode-pendaftaran.destroy');
        });
    });

// Halaman orang tua (layout khusus HP): beranda, anak saya & klaim, akun (FR-1, FR-1b; Ringkasan v1.11 #36)
Route::middleware(['auth', 'role:orang_tua'])
    ->prefix('ortu')
    ->name('ortu.')
    ->group(function () {
        Route::get('/', [OrangTuaController::class, 'beranda'])->name('beranda');
        Route::get('anak', [OrangTuaController::class, 'anakSaya'])->name('anak');
        Route::post('anak', [OrangTuaController::class, 'klaim'])->name('anak.klaim');
        Route::delete('anak/{siswa}', [OrangTuaController::class, 'lepas'])->name('anak.lepas');
        Route::get('anak/{siswa}/kegiatan', [KegiatanAnakController::class, 'show'])->name('anak.kegiatan');
        Route::post('anak/{siswa}/kegiatan', [KegiatanAnakController::class, 'daftar'])->name('anak.daftar');
        Route::get('tagihan', [OrangTuaController::class, 'tagihan'])->name('tagihan');
        // Transfer manual + "Saya sudah bayar" (FR-15b, Ringkasan v1.11 #41)
        Route::get('bayar', [KonfirmasiTransferController::class, 'bayar'])->name('bayar');
        Route::post('bayar', [KonfirmasiTransferController::class, 'ajukan'])->name('bayar.ajukan');
        Route::delete('konfirmasi/{konfirmasiTransfer}', [KonfirmasiTransferController::class, 'batalkan'])->name('konfirmasi.batalkan');
        Route::get('akun', [OrangTuaController::class, 'akun'])->name('akun');
    });
