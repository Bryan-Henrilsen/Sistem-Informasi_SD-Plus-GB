<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Konfirmasi "Saya sudah bayar" dari orang tua untuk transfer manual (FR-15b, Ringkasan v1.11 #41).
// Klaim, bukan uang: TU yang mencatatnya menjadi transaksi setelah mengecek mutasi, atau menolaknya.
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('konfirmasi_transfer', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id')->constrained('siswa');
            $table->foreignId('diajukan_oleh')->constrained('users'); // orang tua
            $table->unsignedBigInteger('nominal'); // total sisa tagihan saat diajukan
            $table->date('tanggal_transfer');
            $table->string('nama_pengirim', 100)->nullable(); // pemilik rekening pengirim, untuk dicocokkan dengan mutasi
            $table->string('status', 20)->default('menunggu'); // menunggu / dicatat / ditolak / dibatalkan
            $table->foreignId('transaksi_id')->nullable()->constrained('transaksi'); // terisi saat TU mencatat
            $table->text('alasan_ditolak')->nullable();
            $table->foreignId('diproses_oleh')->nullable()->constrained('users'); // TU yang mencatat/menolak
            $table->timestamp('diproses_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        DB::statement('ALTER TABLE konfirmasi_transfer ADD CONSTRAINT konfirmasi_transfer_nominal_positif
            CHECK (nominal > 0)');
        DB::statement("ALTER TABLE konfirmasi_transfer ADD CONSTRAINT konfirmasi_transfer_dicatat_ada_transaksi
            CHECK ((status = 'dicatat') = (transaksi_id IS NOT NULL))");
        DB::statement("ALTER TABLE konfirmasi_transfer ADD CONSTRAINT konfirmasi_transfer_ditolak_beralasan
            CHECK (status <> 'ditolak' OR alasan_ditolak IS NOT NULL)");
        DB::statement('ALTER TABLE konfirmasi_transfer ADD CONSTRAINT konfirmasi_transfer_diproses_lengkap
            CHECK ((diproses_oleh IS NULL) = (diproses_at IS NULL))');

        Schema::create('konfirmasi_transfer_tagihan', function (Blueprint $table) {
            $table->foreignId('konfirmasi_transfer_id')->constrained('konfirmasi_transfer')->cascadeOnDelete();
            $table->foreignId('tagihan_id')->constrained('tagihan');

            $table->primary(['konfirmasi_transfer_id', 'tagihan_id']);
            $table->index('tagihan_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('konfirmasi_transfer_tagihan');
        Schema::dropIfExists('konfirmasi_transfer');
    }
};
