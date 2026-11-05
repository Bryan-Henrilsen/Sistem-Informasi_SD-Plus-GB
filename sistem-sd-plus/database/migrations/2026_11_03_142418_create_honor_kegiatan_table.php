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
        Schema::create('honor_kegiatan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('periode_laporan_id')->constrained('periode_laporan');
            $table->foreignId('les_tambahan_id')->nullable()->constrained('les_tambahan');
            $table->foreignId('ekstrakurikuler_id')->nullable()->constrained('ekstrakurikuler'); // kegiatan induk
            $table->unsignedBigInteger('total_pendapatan'); // seluruh alokasi yang diakui di periode ini
            $table->unsignedBigInteger('dasar_honor'); // total_pendapatan tanpa biaya_non_honor; ini yang dibagi persen
            $table->jsonb('snapshot_persen'); // salinan persen skema_honor yang dipakai saat dihitung
            $table->timestamps();

            $table->unique(['periode_laporan_id', 'les_tambahan_id']);
            $table->unique(['periode_laporan_id', 'ekstrakurikuler_id']);
        });

        DB::statement('ALTER TABLE honor_kegiatan ADD CONSTRAINT honor_kegiatan_tepat_satu_kegiatan
            CHECK ((les_tambahan_id IS NULL) <> (ekstrakurikuler_id IS NULL))');

        DB::statement('ALTER TABLE honor_kegiatan ADD CONSTRAINT honor_kegiatan_dasar_honor_valid
            CHECK (dasar_honor <= total_pendapatan)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('honor_kegiatan');
    }
};
