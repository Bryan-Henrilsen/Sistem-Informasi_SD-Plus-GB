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
        // Laporan bulanan (FR-14): siapa yang mengajukan, dan alasan bila Bendahara mengembalikannya ke TU (Ringkasan v1.11 #31)
        Schema::table('periode_laporan', function (Blueprint $table) {
            $table->foreignId('diajukan_oleh')->nullable()->constrained('users'); // TU
            $table->timestamp('diajukan_at')->nullable();
            $table->text('alasan_dikembalikan')->nullable();
            $table->foreignId('dikembalikan_oleh')->nullable()->constrained('users'); // Bendahara
            $table->timestamp('dikembalikan_at')->nullable();
        });

        DB::statement('ALTER TABLE periode_laporan ADD CONSTRAINT periode_laporan_diajukan_lengkap
            CHECK ((diajukan_oleh IS NULL) = (diajukan_at IS NULL))');

        DB::statement('ALTER TABLE periode_laporan ADD CONSTRAINT periode_laporan_dikembalikan_lengkap
            CHECK ((dikembalikan_oleh IS NULL) = (dikembalikan_at IS NULL)
                AND (dikembalikan_oleh IS NULL) = (alasan_dikembalikan IS NULL))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE periode_laporan DROP CONSTRAINT IF EXISTS periode_laporan_diajukan_lengkap');
        DB::statement('ALTER TABLE periode_laporan DROP CONSTRAINT IF EXISTS periode_laporan_dikembalikan_lengkap');

        Schema::table('periode_laporan', function (Blueprint $table) {
            $table->dropConstrainedForeignId('diajukan_oleh');
            $table->dropColumn('diajukan_at');
            $table->dropColumn('alasan_dikembalikan');
            $table->dropConstrainedForeignId('dikembalikan_oleh');
            $table->dropColumn('dikembalikan_at');
        });
    }
};
