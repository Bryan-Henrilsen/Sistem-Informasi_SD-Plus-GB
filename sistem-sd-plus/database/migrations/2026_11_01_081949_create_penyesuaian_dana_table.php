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
        Schema::create('penyesuaian_dana', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pendaftaran_id')->constrained('pendaftaran');
            $table->string('jenis_penyesuaian', 30); // alih_kegiatan / kelebihan_transfer / kegiatan_tidak_berlangsung / refund_berhenti
            $table->foreignId('periode_asal_id')->constrained('periode_laporan');
            $table->foreignId('periode_tujuan_id')->nullable()->constrained('periode_laporan'); // kosong untuk refund
            $table->unsignedBigInteger('nominal'); // rupiah
            $table->string('status', 20); // selesai / proses / berhasil
            $table->date('tanggal_proses');
            $table->foreignId('diproses_oleh')->constrained('users'); // TU
            $table->text('keterangan')->nullable();
            $table->timestamps();
        });

        DB::statement('ALTER TABLE penyesuaian_dana ADD CONSTRAINT penyesuaian_dana_nominal_positif
            CHECK (nominal > 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('penyesuaian_dana');
    }
};
