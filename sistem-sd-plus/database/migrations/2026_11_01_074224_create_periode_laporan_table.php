<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('periode_laporan', function (Blueprint $table) {
            $table->id();
            $table->date('bulan')->unique(); // selalu tanggal 1, misalnya 2026-08-01
            $table->date('tanggal_mulai');
            $table->date('tanggal_selesai');
            $table->string('status', 20)->default('terbuka'); // terbuka / diajukan / ditutup
            $table->foreignId('ditutup_oleh')->nullable()->constrained('users'); // Bendahara
            $table->timestamp('ditutup_at')->nullable();
            $table->foreignId('honor_diketahui_oleh')->nullable()->constrained('users'); // Kepala Sekolah
            $table->timestamp('honor_diketahui_at')->nullable();
            $table->timestamps();
        });

        DB::statement('ALTER TABLE periode_laporan ADD CONSTRAINT periode_laporan_bulan_tanggal_1
            CHECK (EXTRACT(DAY FROM bulan) = 1)');

        DB::statement('ALTER TABLE periode_laporan ADD CONSTRAINT periode_laporan_tanggal_valid
            CHECK (tanggal_mulai <= tanggal_selesai)');

        DB::statement("ALTER TABLE periode_laporan ADD CONSTRAINT periode_laporan_tidak_beririsan
            EXCLUDE USING gist (daterange(tanggal_mulai, tanggal_selesai, '[]') WITH &&)");

        DB::statement('ALTER TABLE periode_laporan ADD CONSTRAINT periode_laporan_ditutup_lengkap
            CHECK ((ditutup_oleh IS NULL) = (ditutup_at IS NULL))');

        DB::statement('ALTER TABLE periode_laporan ADD CONSTRAINT periode_laporan_honor_diketahui_lengkap
            CHECK ((honor_diketahui_oleh IS NULL) = (honor_diketahui_at IS NULL))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('periode_laporan');
    }
};
