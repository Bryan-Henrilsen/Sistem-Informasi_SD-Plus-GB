<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Tabel waktu_pelajaran
        Schema::create('waktu_pelajaran', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tahun_ajaran_id')->constrained('tahun_ajaran')->restrictOnDelete();
            $table->integer('urutan');
            $table->integer('jam_ke')->nullable(); // null untuk istirahat/upacara
            $table->string('nama');
            $table->time('jam_mulai');
            $table->time('jam_selesai');
            $table->string('jenis_kegiatan'); // kbm / istirahat / upacara
            $table->integer('jenjang_min')->default(1);
            $table->integer('jenjang_max')->default(6);
        });

        // Constraint tambahan PostgreSQL (tidak disupport schema builder laravel)
        DB::statement('ALTER TABLE waktu_pelajaran ADD CONSTRAINT check_jenjang_waktu CHECK (jenjang_min <= jenjang_max)');
        DB::statement('ALTER TABLE waktu_pelajaran ADD CONSTRAINT check_jenjang_min_waktu CHECK (jenjang_min BETWEEN 1 AND 6)');
        DB::statement('ALTER TABLE waktu_pelajaran ADD CONSTRAINT check_jenjang_max_waktu CHECK (jenjang_max BETWEEN 1 AND 6)');


        // 2. Tabel waktu_pelajaran_hari
        Schema::create('waktu_pelajaran_hari', function (Blueprint $table) {
            // Sesuai PRD: FK cascade
            $table->foreignId('waktu_pelajaran_id')->constrained('waktu_pelajaran')->cascadeOnDelete();
            $table->tinyInteger('hari'); // 1=Senin .. 5=Jumat
            
            // Sesuai Evaluation Report: Membentuk Primary Key Komposit, tanpa auto-increment id()
            $table->primary(['waktu_pelajaran_id', 'hari']);
        });

        DB::statement('ALTER TABLE waktu_pelajaran_hari ADD CONSTRAINT check_hari_waktu CHECK (hari BETWEEN 1 AND 5)');


        // 3. Tabel kisi_kisi_tugas
        Schema::create('kisi_kisi_tugas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tahun_ajaran_id')->constrained('tahun_ajaran')->restrictOnDelete();
            $table->foreignId('guru_id')->constrained('guru')->restrictOnDelete();
            $table->foreignId('jenis_tugas_id')->constrained('jenis_tugas')->restrictOnDelete();
            
            // Nullable karena piket & damping tidak terikat mapel/kelas
            $table->foreignId('kelas_id')->nullable()->constrained('kelas')->restrictOnDelete();
            $table->foreignId('mapel_id')->nullable()->constrained('mata_pelajaran')->restrictOnDelete();
            
            $table->integer('jumlah_jam');
        });

        // Constraint ketat untuk menjamin konsistensi NULL pada mapel & kelas
        DB::statement('ALTER TABLE kisi_kisi_tugas ADD CONSTRAINT check_kelas_mapel_null CHECK ((kelas_id IS NULL) = (mapel_id IS NULL))');
        
        // Memakai fitur khusus PostgreSQL 15+ untuk mengatasi masalah Unique pada kolom NULL
        DB::statement('ALTER TABLE kisi_kisi_tugas ADD CONSTRAINT unique_kisi_tugas UNIQUE NULLS NOT DISTINCT (tahun_ajaran_id, guru_id, jenis_tugas_id, kelas_id, mapel_id)');


        // 4. Tabel jadwal_mengajar
        Schema::create('jadwal_mengajar', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kisi_kisi_tugas_id')->constrained('kisi_kisi_tugas')->restrictOnDelete();
            $table->foreignId('waktu_pelajaran_id')->constrained('waktu_pelajaran')->restrictOnDelete();
            
            // Kolom salinan untuk memudahkan query baca
            $table->foreignId('guru_id')->constrained('guru')->restrictOnDelete();
            $table->foreignId('jenis_tugas_id')->constrained('jenis_tugas')->restrictOnDelete();
            $table->foreignId('kelas_id')->nullable()->constrained('kelas')->restrictOnDelete();
            
            // Sesuai Evaluation Report & PRD: Wajib cascadeOnDelete() untuk guru damping yang menempel
            $table->foreignId('parent_jadwal_id')->nullable()->constrained('jadwal_mengajar')->cascadeOnDelete();
            
            $table->tinyInteger('hari'); // 1=Senin .. 5=Jumat
        });

        DB::statement('ALTER TABLE jadwal_mengajar ADD CONSTRAINT check_hari_jadwal CHECK (hari BETWEEN 1 AND 5)');
        
        // Anti-bentrok: satu guru hanya satu tugas per slot waktu & hari
        DB::statement('ALTER TABLE jadwal_mengajar ADD CONSTRAINT unique_guru_slot UNIQUE (guru_id, hari, waktu_pelajaran_id)');
        
        // Anti-bentrok: satu kelas hanya boleh punya 1 guru utama dan 1 guru damping per slot waktu & hari
        DB::statement('ALTER TABLE jadwal_mengajar ADD CONSTRAINT unique_kelas_slot UNIQUE NULLS NOT DISTINCT (kelas_id, hari, waktu_pelajaran_id, jenis_tugas_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('jadwal_mengajar');
        Schema::dropIfExists('kisi_kisi_tugas');
        Schema::dropIfExists('waktu_pelajaran_hari');
        Schema::dropIfExists('waktu_pelajaran');
    }
};