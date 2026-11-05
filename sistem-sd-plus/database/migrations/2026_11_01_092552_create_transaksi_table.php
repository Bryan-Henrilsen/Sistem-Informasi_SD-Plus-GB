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
        Schema::create('transaksi', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id')->constrained('siswa');
            $table->foreignId('laporan_harian_id')->constrained('laporan_harian'); // laporan hari pencatatan
            $table->date('tanggal'); // tanggal uang masuk
            $table->string('metode', 20); // cash / transfer / online
            $table->unsignedBigInteger('nominal'); // rupiah, tanpa biaya admin gateway
            $table->string('status', 20); // terverifikasi / pending / ditolak
            $table->string('catatan_transfer')->nullable(); // misalkan nama pengirim / no referensi
            $table->string('sumber_input', 20)->default('tu'); // tu / bendahara / sistem
            $table->foreignId('diinput_oleh')->nullable()->constrained('users'); // NULL hanya untuk sistem
            $table->foreignId('diverifikasi_oleh')->nullable()->constrained('users'); // TU
            $table->timestamp('diverifikasi_at')->nullable();
            $table->string('status_approval_bendahara', 20)->nullable(); // belum_dicek / disetujui; NULL selain transfer
            $table->foreignId('diapprove_oleh')->nullable()->constrained('users'); // Bendahara
            $table->timestamp('tanggal_approval_bendahara')->nullable();
            $table->text('alasan_ditolak')->nullable();
            $table->foreignId('ditolak_oleh')->nullable()->constrained('users'); // TU atau Bendahara
            $table->timestamp('ditolak_at')->nullable();
            $table->timestamps();
        });

        DB::statement('ALTER TABLE transaksi ADD CONSTRAINT transaksi_nominal_positif
            CHECK (nominal > 0)');

        DB::statement("ALTER TABLE transaksi ADD CONSTRAINT transaksi_diinput_oleh_valid
            CHECK (diinput_oleh IS NOT NULL OR sumber_input = 'sistem')");

        DB::statement('ALTER TABLE transaksi ADD CONSTRAINT transaksi_diverifikasi_lengkap
            CHECK ((diverifikasi_oleh IS NULL) = (diverifikasi_at IS NULL))');

        DB::statement('ALTER TABLE transaksi ADD CONSTRAINT transaksi_approval_lengkap
            CHECK ((diapprove_oleh IS NULL) = (tanggal_approval_bendahara IS NULL))');

        DB::statement('ALTER TABLE transaksi ADD CONSTRAINT transaksi_ditolak_lengkap
            CHECK ((ditolak_oleh IS NULL) = (ditolak_at IS NULL)
                AND (ditolak_oleh IS NULL) = (alasan_ditolak IS NULL))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transaksi');
    }
};
