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
        Schema::create('les_tambahan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kelas_id')->unique()->constrained('kelas');
            $table->foreignId('tahun_ajaran_id')->constrained('tahun_ajaran');
            $table->unsignedBigInteger('biaya'); // rupiah, tanpa desimal
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('les_tambahan');
    }
};
