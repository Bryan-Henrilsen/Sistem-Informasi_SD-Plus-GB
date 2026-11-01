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
        Schema::create('pendaftaran', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id')->constrained('siswa');
            $table->foreignId('les_tambahan_id')->nullable()->constrained('les_tambahan');
            $table->foreignId('ekstrakurikuler_id')->nullable()->constrained('ekstrakurikuler');
            $table->date('tanggal_daftar');
            $table->string('status', 20)->default('aktif'); // aktif / berhenti / pindah
            $table->date('tanggal_efektif_berhenti')->nullable();
            $table->foreignId('pindah_ke_id')->nullable()->constrained('pendaftaran'); // diisi jika status = pindah
            $table->timestamps();
        });

        DB::statement('ALTER TABLE pendaftaran ADD CONSTRAINT pendaftaran_tepat_satu_kegiatan
            CHECK ((les_tambahan_id IS NULL) <> (ekstrakurikuler_id IS NULL))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pendaftaran');
    }
};
