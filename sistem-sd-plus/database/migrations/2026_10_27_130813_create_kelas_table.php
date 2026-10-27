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
        Schema::create('kelas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tahun_ajaran_id')->constrained('tahun_ajaran');
            $table->unsignedTinyInteger('jenjang'); // 1-6
            $table->string('nama_kelas', 10); // 1A
            $table->string('nama_pelindung')->nullable(); // "Gregory"
            $table->foreignId('wali_kelas_id')->nullable()->constrained('guru')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tahun_ajaran_id', 'nama_kelas']);
        });

        DB::statement('ALTER TABLE kelas ADD CONSTRAINT kelas_jenjang_valid
            CHECK (jenjang BETWEEN 1 AND 6)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kelas');
    }
};
