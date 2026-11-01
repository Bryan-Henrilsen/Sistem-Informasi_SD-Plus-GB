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
        Schema::create('tagihan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pendaftaran_id')->constrained('pendaftaran');
            $table->date('bulan_tagihan'); // selalu tanggal 1, misalkan 2026-08-01
            $table->unsignedBigInteger('nominal'); // biaya penuh kegiatan (rupiah)
            $table->unsignedBigInteger('diskon')->default(0); // potongan rupiah, misalkan anak guru 50%
            $table->string('status', 20)->default('belum'); // belum / lunas / dibatalkan
            $table->timestamps();
            $table->unique(['pendaftaran_id', 'bulan_tagihan']);
        });

        DB::statement('ALTER TABLE tagihan ADD CONSTRAINT tagihan_bulan_tanggal_1
            CHECK (EXTRACT(DAY FROM bulan_tagihan) = 1)');

        DB::statement('ALTER TABLE tagihan ADD CONSTRAINT tagihan_diskon_valid
            CHECK(diskon <= nominal)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tagihan');
    }
};
