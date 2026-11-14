<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('siswa_kelas', function (Blueprint $table) {
            // Terisi = siswa dibebaskan dari kewajiban ekskul (FR-33) di tahun ajaran ini, mis. karena sakit (Ringkasan v1.11 #30)
            $table->text('alasan_bebas_ekskul')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('siswa_kelas', function (Blueprint $table) {
            $table->dropColumn('alasan_bebas_ekskul');
        });
    }
};
