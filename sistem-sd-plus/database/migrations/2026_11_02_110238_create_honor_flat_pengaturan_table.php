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
        Schema::create('honor_flat_pengaturan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('honor_flat_jenis_id')->constrained('honor_flat_jenis');
            $table->foreignId('tahun_ajaran_id')->constrained('tahun_ajaran');
            $table->date('berlaku_sejak'); // perubahan nominal/penerima = baris baru
            $table->unsignedBigInteger('nominal_per_guru'); // rupiah, diterima penuh oleh setiap penerima
            $table->foreignId('diatur_oleh')->constrained('users'); // Kepala Sekolah
            $table->timestamps();

            $table->unique(['honor_flat_jenis_id', 'berlaku_sejak']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('honor_flat_pengaturan');
    }
};
