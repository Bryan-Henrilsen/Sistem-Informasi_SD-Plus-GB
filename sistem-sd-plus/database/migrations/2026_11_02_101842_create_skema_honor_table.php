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
        Schema::create('skema_honor', function (Blueprint $table) {
            $table->id();
            $table->string('tipe_kegiatan', 20); // ekstrakurikuler / les_tambahan
            $table->string('jenis_pengajar', 20)->nullable(); // internal / eksternal; khusus ekstrakurikuler
            $table->decimal('persen_pengajar', 5, 2);
            $table->decimal('persen_guru_pendamping', 5, 2)->nullable();
            $table->decimal('persen_kas_sekolah', 5, 2);
            $table->decimal('persen_yayasan', 5, 2)->nullable(); // khusus les tambahan
            $table->decimal('persen_penanggung_jawab', 5, 2);
            $table->decimal('persen_bendahara', 5, 2);
            $table->decimal('persen_admin_tu', 5, 2);
            $table->decimal('persen_admin_it', 5, 2);
            $table->date('berlaku_sejak'); // perubahan persen = baris baru, riwayat tersimpan
            $table->timestamps();
        });

        DB::statement('ALTER TABLE skema_honor ADD CONSTRAINT skema_honor_unik_berlaku
            UNIQUE NULLS NOT DISTINCT (tipe_kegiatan, jenis_pengajar, berlaku_sejak)');

        DB::statement('ALTER TABLE skema_honor ADD CONSTRAINT skema_honor_total_100
            CHECK (persen_pengajar + COALESCE(persen_guru_pendamping, 0) + persen_kas_sekolah
                + COALESCE(persen_yayasan, 0) + persen_penanggung_jawab + persen_bendahara
                + persen_admin_tu + persen_admin_it = 100)');

        DB::statement('ALTER TABLE skema_honor ADD CONSTRAINT skema_honor_persen_tidak_negatif
            CHECK (LEAST(persen_pengajar, persen_guru_pendamping, persen_kas_sekolah, persen_yayasan,
                persen_penanggung_jawab, persen_bendahara, persen_admin_tu, persen_admin_it) >= 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('skema_honor');
    }
};
