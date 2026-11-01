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
        Schema::create('ekstrakurikuler', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tahun_ajaran_id')->constrained('tahun_ajaran');
            $table->foreignId('parent_ekstrakurikuler_id')->nullable()->constrained('ekstrakurikuler'); // null = kegiatan induk
            $table->foreignId('pendamping_user_id')->nullable()->constrained('users'); // guru atau staf
            $table->string('nama');
            $table->unsignedBigInteger('biaya'); // 0 untuk olimpiade
            $table->unsignedBigInteger('kuota')->nullable(); // null = tanpa batas
            $table->string('kategori_pendamping', 20)->nullable(); // seni / olahraga
            $table->boolean('wajib_otomatis')->default(false);
            $table->unsignedTinyInteger('jenjang_min')->nullable(); // jenjang sasaran, null = semua jenjang
            $table->unsignedTinyInteger('jenjang_max')->nullable();
            $table->boolean('honor_flat')->default(false);
            $table->timestamps();
        });

        DB::statement('ALTER TABLE ekstrakurikuler ADD CONSTRAINT ekstrakurikuler_jenjang_valid
            CHECK (
                (
                    jenjang_min IS NULL
                    AND jenjang_max IS NULL
                    AND NOT wajib_otomatis
                )
                OR
                (
                    jenjang_min IS NOT NULL
                    AND jenjang_max IS NOT NULL
                    AND jenjang_min BETWEEN 1 AND 6
                    AND jenjang_max BETWEEN jenjang_min AND 6
                )
            )');

        DB::statement('ALTER TABLE ekstrakurikuler ADD CONSTRAINT ekstrakurikuler_parent_bukan_diri_sendiri
            CHECK (parent_ekstrakurikuler_id <> id)'); // <> === !=
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ekstrakurikuler');
    }
};
