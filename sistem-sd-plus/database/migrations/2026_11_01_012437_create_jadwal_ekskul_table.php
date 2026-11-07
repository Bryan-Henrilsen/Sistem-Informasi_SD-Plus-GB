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
        Schema::create('jadwal_ekskul', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ekstrakurikuler_id')->constrained('ekstrakurikuler')->cascadeOnDelete();
            $table->unsignedTinyInteger('hari'); // 5 = jumat, 6 = sabtu
            $table->time('jam_mulai');
            $table->time('jam_selesai');
            $table->unsignedTinyInteger('jenjang_min')->nullable(); // diisi jika jam beda per jenjang (basket)
            $table->unsignedTinyInteger('jenjang_max')->nullable();
            $table->string('lokasi');
            $table->timestamps();
        });

        DB::statement('ALTER TABLE jadwal_ekskul ADD CONSTRAINT jadwal_ekskul_hari_valid
            CHECK (hari between 1 and 6)');

        DB::statement('ALTER TABLE jadwal_ekskul ADD CONSTRAINT jadwal_ekskul_jam_valid
            CHECK (jam_mulai < jam_selesai)');

        DB::statement('ALTER TABLE jadwal_ekskul ADD CONSTRAINT jadwal_ekskul_jenjang_valid
            CHECK (
                (jenjang_min IS NULL AND jenjang_max IS NULL)
                OR
                (jenjang_min IS NOT NULL AND jenjang_max IS NOT NULL
                    AND jenjang_min BETWEEN 1 AND 6
                    AND jenjang_max BETWEEN jenjang_min AND 6)
            )');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('jadwal_ekskul');
    }
};
