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
        Schema::create('ekskul_pendamping', function (Blueprint $table) {
            $table->foreignId('ekstrakurikuler_id')->constrained('ekstrakurikuler')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete(); // guru atau staf
            $table->timestamps();
            $table->primary(['ekstrakurikuler_id', 'user_id']);
        });

        DB::statement('INSERT INTO ekskul_pendamping (ekstrakurikuler_id, user_id, created_at, updated_at)
            SELECT id, pendamping_user_id, NOW(), NOW() FROM ekstrakurikuler WHERE pendamping_user_id IS NOT NULL');

        Schema::table('ekstrakurikuler', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pendamping_user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ekstrakurikuler', function (Blueprint $table) {
            $table->foreignId('pendamping_user_id')->nullable()->constrained('users');
        });

        // Kolom hanya muat satu orang: kembalikan pendamping dengan id terkecil
        DB::statement('UPDATE ekstrakurikuler e SET pendamping_user_id = (SELECT MIN(p.user_id) FROM ekskul_pendamping p WHERE p.ekstrakurikuler_id = e.id)');

        Schema::dropIfExists('ekskul_pendamping');
    }
};
