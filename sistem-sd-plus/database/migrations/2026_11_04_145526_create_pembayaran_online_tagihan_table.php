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
        Schema::create('pembayaran_online_tagihan', function (Blueprint $table) {
            $table->foreignId('pembayaran_online_id')->constrained('pembayaran_online')->cascadeOnDelete();
            $table->foreignId('tagihan_id')->constrained('tagihan');
            $table->unsignedBigInteger('nominal'); // sisa tagihan saat order dibuat
            $table->timestamps();

            $table->primary(['pembayaran_online_id', 'tagihan_id']);
            $table->index('tagihan_id');
        });

        DB::statement('ALTER TABLE pembayaran_online_tagihan ADD CONSTRAINT pembayaran_online_tagihan_nominal_positif
            CHECK (nominal > 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pembayaran_online_tagihan');
    }
};
