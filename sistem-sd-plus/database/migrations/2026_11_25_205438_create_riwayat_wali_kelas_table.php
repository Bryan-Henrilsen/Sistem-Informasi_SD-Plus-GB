<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Riwayat wali kelas untuk honor les tambahan (Ringkasan v1.11 #50). Baris hanya dibuat saat TU memakai "Ganti wali kelas";
// kelas tanpa riwayat memakai kelas.wali_kelas_id untuk seluruh bulan. Tabel kelas (data master bersama) tidak diubah
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('riwayat_wali_kelas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kelas_id')->constrained('kelas')->cascadeOnDelete();
            $table->foreignId('guru_id')->constrained('guru');
            $table->date('tanggal_mulai')->nullable(); // NULL = sejak awal tahun ajaran
            $table->date('tanggal_selesai')->nullable(); // NULL = masih menjadi wali kelas
            $table->foreignId('dicatat_oleh')->constrained('users');
            $table->timestamps();

            $table->index(['kelas_id', 'tanggal_mulai']);
        });

        DB::statement('ALTER TABLE riwayat_wali_kelas ADD CONSTRAINT riwayat_wali_kelas_tanggal_urut CHECK (tanggal_selesai IS NULL OR tanggal_mulai IS NULL OR tanggal_selesai >= tanggal_mulai)');
        // Satu kelas hanya punya satu wali yang masih berjalan
        DB::statement('CREATE UNIQUE INDEX riwayat_wali_kelas_aktif_unik ON riwayat_wali_kelas (kelas_id) WHERE tanggal_selesai IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('riwayat_wali_kelas');
    }
};
