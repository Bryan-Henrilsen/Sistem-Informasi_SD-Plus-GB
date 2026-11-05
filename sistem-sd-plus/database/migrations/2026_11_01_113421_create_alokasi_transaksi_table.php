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
        Schema::create('alokasi_transaksi', function (Blueprint $table) {
            $table->foreignId('transaksi_id')->constrained('transaksi');
            $table->foreignId('tagihan_id')->constrained('tagihan');
            $table->unsignedBigInteger('nominal'); // bagian transaksi untuk tagihan ini (rupiah)
            $table->foreignId('diakui_periode_id')->nullable()->constrained('periode_laporan'); // diisi saat tutup buku
            $table->timestamps();

            $table->primary(['transaksi_id', 'tagihan_id']);
            $table->index('tagihan_id');
        });

        DB::statement('ALTER TABLE alokasi_transaksi ADD CONSTRAINT alokasi_transaksi_nominal_positif
            CHECK (nominal > 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('alokasi_transaksi');
    }
};
