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
        Schema::table('penyesuaian_dana', function (Blueprint $table) {
            $table->foreignId('transaksi_id')->nullable()->constrained('transaksi'); // transfer asal kelebihan yang direfund
        });

        // Terisi untuk kelebihan transfer, kosong untuk jenis lain (Ringkasan v1.11 #22)
        DB::statement("ALTER TABLE penyesuaian_dana ADD CONSTRAINT penyesuaian_dana_transaksi_valid
            CHECK ((transaksi_id IS NOT NULL) = (jenis_penyesuaian = 'kelebihan_transfer'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('penyesuaian_dana', function (Blueprint $table) {
            $table->dropConstrainedForeignId('transaksi_id');
        });
    }
};
