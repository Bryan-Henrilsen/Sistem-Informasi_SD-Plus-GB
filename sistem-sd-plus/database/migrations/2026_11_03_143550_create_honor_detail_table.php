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
        Schema::create('honor_detail', function (Blueprint $table) {
            $table->id();
            $table->foreignId('periode_laporan_id')->constrained('periode_laporan');
            $table->foreignId('honor_kegiatan_id')->nullable()->constrained('honor_kegiatan')->cascadeOnDelete(); // sumber persentase
            $table->foreignId('honor_flat_pengaturan_id')->nullable()->constrained('honor_flat_pengaturan'); // sumber flat
            $table->foreignId('penerima_user_id')->nullable()->constrained('users'); // guru / staf
            $table->foreignId('penerima_pengajar_id')->nullable()->constrained('pengajar_ekskul'); // pengajar eksternal
            $table->string('peran', 30); // pengajar / guru_pendamping / kas_sekolah / yayasan / penanggung_jawab / bendahara / admin_tu / admin_it / honor_flat
            $table->unsignedBigInteger('nominal'); // rupiah
            $table->timestamps();

            $table->index(['periode_laporan_id', 'penerima_user_id']);
        });

        DB::statement('ALTER TABLE honor_detail ADD CONSTRAINT honor_detail_tepat_satu_sumber
            CHECK ((honor_kegiatan_id IS NULL) <> (honor_flat_pengaturan_id IS NULL))');

        DB::statement('ALTER TABLE honor_detail ADD CONSTRAINT honor_detail_maks_satu_penerima
            CHECK (penerima_user_id IS NULL OR penerima_pengajar_id IS NULL)');

        DB::statement('ALTER TABLE honor_detail ADD CONSTRAINT honor_detail_flat_wajib_penerima
            CHECK (honor_flat_pengaturan_id IS NULL OR penerima_user_id IS NOT NULL)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('honor_detail');
    }
};
