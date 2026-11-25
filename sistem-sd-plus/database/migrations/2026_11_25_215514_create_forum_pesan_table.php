<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Forum privat guru ↔ Kepala Sekolah (PRD B.2, Ringkasan v1.11 #51): satu percakapan berkelanjutan per guru.
// Pesan tidak bisa diubah (tanpa updated_at); pengirim boleh menghapus dalam 15 menit (dijaga ForumService)
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forum_pesan', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guru_id')->constrained('guru'); // pemilik thread
            $table->foreignId('pengirim_id')->constrained('users'); // guru itu sendiri atau Kepala Sekolah
            $table->text('isi');
            $table->timestamp('dibaca_at')->nullable(); // diisi saat penerima membuka thread
            $table->timestamp('created_at')->useCurrent();

            $table->index(['guru_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forum_pesan');
    }
};
