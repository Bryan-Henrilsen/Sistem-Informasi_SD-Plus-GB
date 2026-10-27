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
        Schema::create('siswa', function (Blueprint $table) {
            $table->id();
            $table->foreignId('anak_guru_id')->nullable()->constrained('guru')->nullOnDelete();
            $table->string('nis', 20)->nullable()->unique();
            $table->string('nisn', 20)->nullable()->unique();
            $table->date('tanggal_lahir')->nullable();
            $table->char('jenis_kelamin', 1)->nullable(); // L / P
            $table->string('nama');
            $table->string('status_sekolah', 20)->default('aktif');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('siswa');
    }
};
