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
        Schema::create('pengajar_ekskul', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->unique()->constrained('users'); // diisi jika internal (guru/staf)
            $table->string('nama');
            $table->string('jenis', 20); // internal / eksternal
            $table->string('no_hp', 20);
            $table->boolean('status_aktif')->default(true);
            $table->timestamps();
        });

        DB::statement("ALTER TABLE pengajar_ekskul ADD CONSTRAINT pengajar_ekskul_jenis_user_valid
            CHECK (
                (jenis = 'internal' AND user_id IS NOT NULL)
                OR
                (jenis = 'eksternal' AND user_id IS NULL)
            )");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pengajar_ekskul');
    }
};
