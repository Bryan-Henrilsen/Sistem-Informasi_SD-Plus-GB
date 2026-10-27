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
        Schema::create('tahun_ajaran', function (Blueprint $table) {
            $table->id();
            $table->string('nama', 9)->unique(); // "2027/2028"
            $table->date('tanggal_mulai');
            $table->date('tanggal_selesai');
            $table->boolean('status_aktif')->default(false);
            $table->timestamps();
        });

        DB::statement('CREATE UNIQUE INDEX tahun_ajaran_satu_aktif
            ON tahun_ajaran (status_aktif) WHERE status_aktif = true');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tahun_ajaran');
    }
};
