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
        Schema::table('ekstrakurikuler', function (Blueprint $table) {
            $table->unsignedBigInteger('biaya_non_honor')->default(0); // misalkan uang kolam, diteruskan ke pihak ketiga
        });

        DB::statement('ALTER TABLE ekstrakurikuler ADD CONSTRAINT ekstrakurikuler_biaya_non_honor_valid
            CHECK (biaya_non_honor <= biaya)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ekstrakurikuler', function (Blueprint $table) {
            $table->dropColumn('biaya_non_honor');
        });
    }
};
