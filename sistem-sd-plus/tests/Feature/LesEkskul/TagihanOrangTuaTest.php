<?php

use App\Domains\LesEkskul\Enums\MetodeTransaksi;
use App\Domains\LesEkskul\Models\LesTambahan;
use App\Domains\LesEkskul\Models\Pendaftaran;
use App\Domains\LesEkskul\Models\PeriodeLaporan;
use App\Domains\LesEkskul\Models\Tagihan;
use App\Domains\LesEkskul\Services\TransaksiService;
use App\Models\Kelas;
use App\Models\Siswa;
use App\Models\SiswaKelas;
use App\Models\TahunAjaran;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Inertia\Testing\AssertableInertia as Assert;

// Tagihan & status pembayaran untuk orang tua (FR-15, FR-32; Ringkasan v1.11 #38)
beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->travelTo('2026-11-10 09:00');

    $this->tu = User::factory()->create()->assignRole('tu');
    $this->ortu = User::factory()->create(['email' => null, 'no_hp' => '081234567890'])->assignRole('orang_tua');

    $ta = TahunAjaran::create(['nama' => '2026/2027', 'tanggal_mulai' => '2026-07-13', 'tanggal_selesai' => '2027-06-30', 'status_aktif' => true]);
    $ta->semester()->createMany([
        ['urutan' => 1, 'nama' => 'Semester 1', 'tanggal_mulai' => '2026-07-13', 'tanggal_selesai' => '2026-12-31'],
        ['urutan' => 2, 'nama' => 'Semester 2', 'tanggal_mulai' => '2027-01-01', 'tanggal_selesai' => '2027-06-30'],
    ]);
    PeriodeLaporan::create(['bulan' => '2026-11-01', 'tanggal_mulai' => '2026-10-23', 'tanggal_selesai' => '2026-11-22']);

    $kelas = Kelas::create(['tahun_ajaran_id' => $ta->id, 'jenjang' => 4, 'nama_kelas' => '4A']);
    $this->anak = Siswa::create(['nama' => 'Anak Fiktif']);
    SiswaKelas::create(['siswa_id' => $this->anak->id, 'kelas_id' => $kelas->id]);
    $this->ortu->anak()->attach($this->anak->id);

    $les = LesTambahan::create(['kelas_id' => $kelas->id, 'biaya' => 100000]);
    $pendaftaran = Pendaftaran::create(['siswa_id' => $this->anak->id, 'les_tambahan_id' => $les->id, 'tanggal_daftar' => '2026-08-01']);
    $this->tagihan = collect(['2026-08-01', '2026-09-01', '2026-10-01', '2026-11-01'])
        ->mapWithKeys(fn (string $bulan) => [substr($bulan, 0, 7) => Tagihan::create(['pendaftaran_id' => $pendaftaran->id, 'bulan_tagihan' => $bulan, 'nominal' => 100000])]);
});

function ttBayar(string $bulan, int $nominal, MetodeTransaksi $metode = MetodeTransaksi::Cash)
{
    return app(TransaksiService::class)->catat(test()->anak, $metode, $nominal, [test()->tagihan[$bulan]->id], test()->tu);
}

test('status tiap tagihan dan tunggakan seperti yang dimengerti orang tua', function () {
    ttBayar('2026-08', 100000); // cash: lunas
    ttBayar('2026-09', 40000); // cash sebagian
    ttBayar('2026-10', 100000, MetodeTransaksi::Transfer); // transfer belum diverifikasi TU
    $ditolak = ttBayar('2026-11', 100000, MetodeTransaksi::Transfer);
    app(TransaksiService::class)->tolak($ditolak, $this->tu, 'Bukti transfer tidak ditemukan di mutasi');

    $this->actingAs($this->ortu)->get('/ortu/tagihan')->assertInertia(fn (Assert $page) => $page
        ->component('les-ekskul/orang-tua/tagihan')
        ->where('anak.0.tagihan', fn ($t) => collect($t)->mapWithKeys(fn ($x) => [substr($x['bulan'], 0, 7) => $x['status']])->all() === [
            '2026-11' => 'belum',
            '2026-10' => 'menunggu_verifikasi',
            '2026-09' => 'sebagian',
            '2026-08' => 'lunas',
        ])
        // Tunggakan = bulan lewat yang belum lunas (sisa September); November belum lewat
        ->where('anak.0.ringkasan.tunggakan', 60000)
        ->where('anak.0.ringkasan.bulan_ini', 100000)
        ->where('anak.0.ringkasan.menunggu_verifikasi', 1)
        ->where('anak.0.ditolak.0.alasan', 'Bukti transfer tidak ditemukan di mutasi')
    );

    $this->actingAs($this->ortu)->get('/ortu')->assertInertia(fn (Assert $page) => $page
        ->where('anak.0.tunggakan', 60000)
        ->where('anak.0.bulan_ini', 100000)
    );
});

test('rekening transfer ditampilkan selama pembayaran online mati dan nomornya diisi', function () {
    config(['les_ekskul.pembayaran_online' => false, 'les_ekskul.rekening' => ['bank' => 'Bank Contoh', 'nomor' => '0000000000', 'atas_nama' => 'Sekolah Contoh']]);
    $this->actingAs($this->ortu)->get('/ortu/tagihan')->assertInertia(fn (Assert $page) => $page->where('rekening.nomor', '0000000000'));

    config(['les_ekskul.pembayaran_online' => true]);
    $this->actingAs($this->ortu)->get('/ortu/tagihan')->assertInertia(fn (Assert $page) => $page->where('rekening', null));

    config(['les_ekskul.pembayaran_online' => false, 'les_ekskul.rekening.nomor' => null]);
    $this->actingAs($this->ortu)->get('/ortu/tagihan')->assertInertia(fn (Assert $page) => $page->where('rekening', null));
});

test('orang tua hanya melihat tagihan anak yang diklaimnya', function () {
    $orangLain = User::factory()->create(['email' => null, 'no_hp' => '081299990000'])->assignRole('orang_tua');

    $this->actingAs($orangLain)->get('/ortu/tagihan')->assertInertia(fn (Assert $page) => $page->where('anak', []));
    $this->actingAs($this->tu)->get('/ortu/tagihan')->assertForbidden();
});
