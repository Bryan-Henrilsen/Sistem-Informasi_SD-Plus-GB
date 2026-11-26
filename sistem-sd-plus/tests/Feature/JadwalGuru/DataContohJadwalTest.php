<?php

use App\Domains\JadwalGuru\Models\JadwalMengajar;
use App\Domains\JadwalGuru\Models\KisiKisiTugas;
use App\Models\TahunAjaran;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DataContohJadwalGuruSeeder;

// Seeder data contoh jadwal untuk demo (Ringkasan v1.11 #52), di atas data master riil dari DatabaseSeeder
test('seeder data contoh mengisi kisi-kisi dan jadwal tanpa bentrok dan tidak dobel bila dijalankan lagi', function () {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DataContohJadwalGuruSeeder::class);

    $ta = TahunAjaran::aktif()->sole();
    $jadwal = JadwalMengajar::whereHas('kisiKisiTugas', fn ($q) => $q->where('tahun_ajaran_id', $ta->id))->with('jenisTugas')->get();

    expect($jadwal->where('jenisTugas.kode', 'utama')->count())->toBeGreaterThan(50)
        ->and($jadwal->where('jenisTugas.kode', 'damping')->count())->toBeGreaterThan(0)
        ->and($jadwal->where('jenisTugas.kode', 'piket')->count())->toBeGreaterThan(0)
        // Satu guru hanya satu tugas per slot (dijaga JadwalService)
        ->and($jadwal->groupBy(fn ($j) => "{$j->guru_id}-{$j->hari}-{$j->waktu_pelajaran_id}")->every(fn ($g) => $g->count() === 1))->toBeTrue();

    $jumlah = KisiKisiTugas::count();
    $this->seed(DataContohJadwalGuruSeeder::class);
    expect(KisiKisiTugas::count())->toBe($jumlah);
});
