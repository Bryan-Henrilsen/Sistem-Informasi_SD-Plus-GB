<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ekskul_pengajar', function (Blueprint $table) {
            $table->foreignId('ekstrakurikuler_id')->constrained('ekstrakurikuler')->cascadeOnDelete();
            $table->foreignId('pengajar_ekskul_id')->constrained('pengajar_ekskul')->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['ekstrakurikuler_id', 'pengajar_ekskul_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ekskul_pengajar');
    }
};
