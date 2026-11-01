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
        Schema::create('laporan_harian', function (Blueprint $table) {
            $table->id();
            $table->foreignId('periode_laporan_id')->constrained('periode_laporan');
            $table->date('tanggal')->unique();
            $table->string('status', 20)->default('draft'); // draft / diajukan / disetujui / ditolak
            $table->foreignId('diajukan_oleh')->nullable()->constrained('users'); // TU
            $table->timestamp('diajukan_at')->nullable();
            $table->foreignId('disetujui_oleh')->nullable()->constrained('users'); // Bendahara
            $table->timestamp('disetujui_at')->nullable();
            $table->text('alasan_ditolak')->nullable();
            $table->foreignId('ditolak_oleh')->nullable()->constrained('users'); // Bendahara
            $table->timestamp('ditolak_at')->nullable();
            $table->timestamps();
        });

        DB::statement('ALTER TABLE laporan_harian ADD CONSTRAINT laporan_harian_diajukan_lengkap
            CHECK ((diajukan_oleh IS NULL) = (diajukan_at IS NULL))');

        DB::statement('ALTER TABLE laporan_harian ADD CONSTRAINT laporan_harian_disetujui_lengkap
            CHECK ((disetujui_oleh IS NULL) = (disetujui_at IS NULL))');

        DB::statement('ALTER TABLE laporan_harian ADD CONSTRAINT laporan_harian_ditolak_lengkap
            CHECK ((ditolak_oleh IS NULL) = (ditolak_at IS NULL)
                AND (ditolak_oleh IS NULL) = (alasan_ditolak IS NULL))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('laporan_harian');
    }
};
