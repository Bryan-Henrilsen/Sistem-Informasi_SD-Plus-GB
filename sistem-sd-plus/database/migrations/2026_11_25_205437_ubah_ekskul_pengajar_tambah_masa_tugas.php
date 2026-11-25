<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Masa tugas pengajar ekskul (Ringkasan v1.11 #50): honor bulan tagihan dibagi per pertemuan antara pengajar lama dan baru.
// PK komposit diganti id supaya pengajar yang pernah berhenti lalu mengajar lagi bisa punya lebih dari satu baris riwayat
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ekskul_pengajar', function (Blueprint $table) {
            $table->dropPrimary(['ekstrakurikuler_id', 'pengajar_ekskul_id']);
        });

        Schema::table('ekskul_pengajar', function (Blueprint $table) {
            $table->id()->first();
            $table->date('tanggal_mulai')->nullable()->after('pengajar_ekskul_id'); // NULL = sejak awal kegiatan
            $table->date('tanggal_selesai')->nullable()->after('tanggal_mulai'); // NULL = masih mengajar
            $table->index(['ekstrakurikuler_id', 'pengajar_ekskul_id']);
        });

        DB::statement('ALTER TABLE ekskul_pengajar ADD CONSTRAINT ekskul_pengajar_tanggal_urut CHECK (tanggal_selesai IS NULL OR tanggal_mulai IS NULL OR tanggal_selesai >= tanggal_mulai)');
        // Satu pengajar hanya punya satu penugasan yang masih berjalan per kegiatan
        DB::statement('CREATE UNIQUE INDEX ekskul_pengajar_aktif_unik ON ekskul_pengajar (ekstrakurikuler_id, pengajar_ekskul_id) WHERE tanggal_selesai IS NULL');
    }

    public function down(): void
    {
        // Riwayat yang sudah selesai dibuang supaya PK komposit lama bisa dipasang lagi
        DB::table('ekskul_pengajar')->whereNotNull('tanggal_selesai')->delete();
        DB::statement('DROP INDEX IF EXISTS ekskul_pengajar_aktif_unik');
        DB::statement('ALTER TABLE ekskul_pengajar DROP CONSTRAINT IF EXISTS ekskul_pengajar_tanggal_urut');

        Schema::table('ekskul_pengajar', function (Blueprint $table) {
            $table->dropIndex(['ekstrakurikuler_id', 'pengajar_ekskul_id']);
            $table->dropColumn(['id', 'tanggal_mulai', 'tanggal_selesai']);
        });

        Schema::table('ekskul_pengajar', function (Blueprint $table) {
            $table->primary(['ekstrakurikuler_id', 'pengajar_ekskul_id']);
        });
    }
};
