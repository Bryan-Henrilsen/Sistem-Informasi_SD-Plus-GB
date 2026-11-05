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
        Schema::create('penerima_peran_honor', function (Blueprint $table) {
            $table->id();
            $table->string('peran', 30); // penanggung_jawab / bendahara / admin_tu / admin_it
            $table->foreignId('user_id')->constrained('users'); // pemegang peran
            $table->date('berlaku_sejak'); // pergantian orang = baris baru
            $table->foreignId('diatur_oleh')->constrained('users'); // Kepala Sekolah
            $table->timestamps();

            $table->unique(['peran', 'berlaku_sejak']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('penerima_peran_honor');
    }
};
