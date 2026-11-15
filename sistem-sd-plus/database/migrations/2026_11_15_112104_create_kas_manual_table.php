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
        // Catatan kas yang tidak bisa dihitung dari data aplikasi untuk Laporan Keuangan Kas (A6 #28, Ringkasan v1.11 #35):
        // saldo awal, pembayaran uang kolam, ATK, admin bank, setoran ke yayasan, pemasukan/pengeluaran lain.
        // Arah (masuk/keluar) dan kas yang terdampak (sekolah/yayasan) ditentukan oleh kategori (enum KategoriKas)
        Schema::create('kas_manual', function (Blueprint $table) {
            $table->id();
            $table->foreignId('periode_laporan_id')->constrained('periode_laporan');
            $table->date('tanggal');
            $table->string('kategori', 30);
            $table->string('uraian')->nullable(); // wajib untuk kategori "lainnya" (dijaga Service)
            $table->unsignedBigInteger('nominal'); // rupiah
            $table->foreignId('dicatat_oleh')->constrained('users'); // Bendahara / Kepala Sekolah
            $table->timestamps();

            $table->index(['periode_laporan_id', 'kategori']);
        });

        DB::statement("ALTER TABLE kas_manual ADD CONSTRAINT kas_manual_kategori_valid CHECK (kategori IN (
            'saldo_awal_sekolah', 'saldo_awal_yayasan', 'pembayaran_kolam', 'atk', 'admin_bank', 'setor_yayasan', 'pemasukan_lain', 'pengeluaran_lain'))");
        DB::statement('ALTER TABLE kas_manual ADD CONSTRAINT kas_manual_nominal_positif CHECK (nominal > 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kas_manual');
    }
};
