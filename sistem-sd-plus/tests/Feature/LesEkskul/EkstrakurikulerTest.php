<?php

use App\Domains\LesEkskul\Models\Ekstrakurikuler;
use App\Domains\LesEkskul\Models\LesTambahan;
use App\Models\Kelas;
use App\Models\TahunAjaran;
use Illuminate\Database\QueryException;

beforeEach(function () {
    $this->tahunAjaran = TahunAjaran::create([
        'nama' => '2026/2027',
        'tanggal_mulai' => '2026-07-01',
        'tanggal_selesai' => '2027-06-30',
        'status_aktif' => true,
    ]);
});

// Helper supaya tiap test cukup menulis kolom yang penting aja
function buatEkskul(array $kolom = []): Ekstrakurikuler
{
    return Ekstrakurikuler::create([
        'tahun_ajaran_id' => TahunAjaran::aktif()->first()->id,
        'nama' => 'Ekskul Uji',
        'biaya' => 100000,
        'kuota' => 20,
        ...$kolom,
    ]);
}

it('menyalin tahun ajaran les tambahan dari kelasnya', function () {
    $kelas = Kelas::create(['tahun_ajaran_id' => $this->tahunAjaran->id, 'jenjang' => 2, 'nama_kelas' => '2A']);

    $les = LesTambahan::create(['kelas_id' => $kelas->id, 'biaya' => 150000]);

    expect($les->tahun_ajaran_id)->toBe($this->tahunAjaran->id);
});

it('hanya menampilkan ekskul yang sesuai jenjang siswa', function () {
    buatEkskul(['nama' => 'Basket']);
    buatEkskul(['nama' => 'Robotik', 'jenjang_min' => 4, 'jenjang_max' => 6]);

    expect(Ekstrakurikuler::untukJenjang(2)->pluck('nama')->all())->toBe(['Basket'])
        ->and(Ekstrakurikuler::untukJenjang(5)->pluck('nama')->all())->toEqualCanonicalizing(['Basket', 'Robotik']);
});

it('tidak menampilkan kelas paralel di form orang tua', function () {
    $english = buatEkskul(['nama' => 'English Club']);
    buatEkskul(['nama' => 'English Club - Starter', 'parent_ekstrakurikuler_id' => $english->id]);

    expect(Ekstrakurikuler::kegiatanInduk()->pluck('nama')->all())->toBe(['English Club']);
});

it('menolak kegiatan wajib tanpa jenjang', function () {
    buatEkskul(['nama' => 'Pramuka', 'wajib_otomatis' => true]);
})->throws(QueryException::class, 'ekstrakurikuler_jenjang_valid');
