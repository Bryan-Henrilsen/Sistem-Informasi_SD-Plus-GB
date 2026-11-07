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
        Schema::create('honor_flat_penerima', function (Blueprint $table) {
            $table->foreignId('honor_flat_pengaturan_id')->constrained('honor_flat_pengaturan')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete(); // guru / staf
            $table->unsignedInteger('jumlah')->default(1); // honor = nominal_per_guru x jumlah (misalkan jumlah soal yang dibuat)
            $table->timestamps();

            $table->primary(['honor_flat_pengaturan_id', 'user_id']);
        });

        DB::statement('ALTER TABLE honor_flat_penerima ADD CONSTRAINT honor_flat_penerima_jumlah_valid
            CHECK (jumlah >= 1)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('honor_flat_penerima');
    }
};
