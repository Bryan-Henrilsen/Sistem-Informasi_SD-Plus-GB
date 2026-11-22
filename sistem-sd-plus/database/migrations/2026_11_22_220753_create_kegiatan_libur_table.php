<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Kegiatan tidak berlangsung sebulan penuh (FR-23, Ringkasan v1.11 #48): tagihan bulan itu dibatalkan / tidak dibuat.
// Ekskul induk = berlaku juga untuk seluruh kelas paralelnya
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('kegiatan_libur', function (Blueprint $table) {
            $table->id();
            $table->foreignId('les_tambahan_id')->nullable()->constrained('les_tambahan');
            $table->foreignId('ekstrakurikuler_id')->nullable()->constrained('ekstrakurikuler');
            $table->date('bulan'); // selalu tanggal 1
            $table->text('alasan');
            // Hasil saat ditandai; penandaan hanya bisa dibatalkan bila tidak ada uang yang dialihkan/direfund
            $table->unsignedInteger('jumlah_tagihan')->default(0);
            $table->unsignedBigInteger('nominal_dialihkan')->default(0);
            $table->unsignedBigInteger('nominal_refund')->default(0);
            $table->foreignId('dicatat_oleh')->constrained('users');
            $table->timestamps();

            $table->unique(['les_tambahan_id', 'bulan']);
            $table->unique(['ekstrakurikuler_id', 'bulan']);
            $table->index('bulan');
        });

        DB::statement('ALTER TABLE kegiatan_libur ADD CONSTRAINT kegiatan_libur_tepat_satu_kegiatan
            CHECK ((les_tambahan_id IS NULL) <> (ekstrakurikuler_id IS NULL))');
        DB::statement('ALTER TABLE kegiatan_libur ADD CONSTRAINT kegiatan_libur_bulan_tanggal_satu
            CHECK (EXTRACT(DAY FROM bulan) = 1)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kegiatan_libur');
    }
};
