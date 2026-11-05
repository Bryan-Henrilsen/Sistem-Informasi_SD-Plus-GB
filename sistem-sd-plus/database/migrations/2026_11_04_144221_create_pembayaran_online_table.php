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
        Schema::create('pembayaran_online', function (Blueprint $table) {
            $table->id();
            $table->string('order_id', 50)->unique(); // dikirim ke gateway, mis. SDP-20260910-0001
            $table->foreignId('siswa_id')->constrained('siswa');
            $table->foreignId('dibuat_oleh')->constrained('users'); // orang tua
            $table->unsignedBigInteger('nominal'); // total tagihan yang dibayar, tanpa biaya admin
            $table->unsignedBigInteger('biaya_admin'); // ditanggung orang tua, bukan pendapatan kegiatan
            $table->unsignedBigInteger('total_bayar'); // nominal + biaya admin
            $table->string('metode_gateway', 30)->nullable(); // misalkan bca_va, dari respons gateway
            $table->string('kode_bayar', 50)->nullable(); // misalkan nomor virtual account
            $table->string('gateway_ref', 100)->nullable(); // id transaksi dari sisi gateway
            $table->string('status', 20)->default('menunggu'); // menunggu / lunas / kedaluwarsa / gagal
            $table->timestamp('kedaluwarsa_at');
            $table->timestamp('dibayar_at')->nullable();
            $table->foreignId('transaksi_id')->nullable()->unique()->constrained('transaksi'); // diisi saat lunas
            $table->jsonb('payload_terakhir')->nullable(); // isi webhook terakhir, untuk penelusuran
            $table->timestamps();
        });

        DB::statement('ALTER TABLE pembayaran_online ADD CONSTRAINT pembayaran_online_nominal_positif
            CHECK (nominal > 0)');

        DB::statement('ALTER TABLE pembayaran_online ADD CONSTRAINT pembayaran_online_total_bayar_valid
            CHECK (total_bayar = nominal + biaya_admin)');

        DB::statement('ALTER TABLE pembayaran_online ADD CONSTRAINT pembayaran_online_dibayar_lengkap
            CHECK ((dibayar_at IS NULL) = (transaksi_id IS NULL))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pembayaran_online');
    }
};
