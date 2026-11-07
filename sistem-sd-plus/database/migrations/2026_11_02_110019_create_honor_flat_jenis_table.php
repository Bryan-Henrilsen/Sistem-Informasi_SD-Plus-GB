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
        Schema::create('honor_flat_jenis', function (Blueprint $table) {
            $table->id();
            $table->string('kode', 30)->unique(); // olimpiade / bina_karakter / pramuka / pembuat_soal_les
            $table->string('nama', 50);
            $table->text('keterangan')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('honor_flat_jenis');
    }
};
