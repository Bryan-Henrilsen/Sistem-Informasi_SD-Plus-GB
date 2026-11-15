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
        Schema::table('ekstrakurikuler', function (Blueprint $table) {
            // Cara hitung honor kegiatan induk berkelas paralel (Ringkasan v1.11 #33):
            // false = digabung seluruh kelas paralel lalu porsi pengajar dibagi rata (FR-16b, mis. English Club);
            // true = per kelas paralel, masing-masing dengan pembayaran, pengajar, dan skemanya sendiri (mis. Tari)
            $table->boolean('honor_per_kelas_paralel')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ekstrakurikuler', function (Blueprint $table) {
            $table->dropColumn('honor_per_kelas_paralel');
        });
    }
};
