<?php

use App\Domains\LesEkskul\Enums\MetodeTransaksi;
use App\Domains\LesEkskul\Models\Ekstrakurikuler;
use App\Domains\LesEkskul\Models\LaporanHarian;
use App\Domains\LesEkskul\Models\Pendaftaran;
use App\Domains\LesEkskul\Models\PeriodeLaporan;
use App\Domains\LesEkskul\Models\Tagihan;
use App\Domains\LesEkskul\Models\Transaksi;
use App\Domains\LesEkskul\Services\TagihanService;
use App\Domains\LesEkskul\Services\TransaksiService;
use App\Models\Kelas;
use App\Models\Siswa;
use App\Models\SiswaKelas;
use App\Models\TahunAjaran;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Inertia\Testing\AssertableInertia as Assert;

// Cetak laporan harian khusus cash untuk arsip fisik ke Bendahara (#49)
beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->travelTo('2026-08-10 09:00');

    $this->tu = User::factory()->create()->assignRole('tu');

    $ta = TahunAjaran::create(['nama' => '2026/2027', 'tanggal_mulai' => '2026-07-13', 'tanggal_selesai' => '2027-06-30', 'status_aktif' => true]);
    PeriodeLaporan::create(['bulan' => '2026-08-01', 'tanggal_mulai' => '2026-07-23', 'tanggal_selesai' => '2026-08-22']);
    $basket = Ekstrakurikuler::create(['tahun_ajaran_id' => $ta->id, 'nama' => 'Basket', 'biaya' => 80000]);

    // Dua siswa di kelas berbeda, masing-masing ikut Basket
    $this->siswa = collect(['Ani Cash' => '3A', 'Budi Transfer' => '4B'])->map(function (string $namaKelas, string $nama) use ($ta, $basket) {
        $kelas = Kelas::create(['tahun_ajaran_id' => $ta->id, 'jenjang' => (int) $namaKelas[0], 'nama_kelas' => $namaKelas]);
        $siswa = Siswa::create(['nama' => $nama]);
        SiswaKelas::create(['siswa_id' => $siswa->id, 'kelas_id' => $kelas->id]);
        $pendaftaran = Pendaftaran::create(['siswa_id' => $siswa->id, 'ekstrakurikuler_id' => $basket->id, 'tanggal_daftar' => '2026-07-20']);
        app(TagihanService::class)->buatSampai($pendaftaran, '2026-09-01');

        return $siswa;
    });
});

function bayarUntukCetak(Siswa $siswa, array $bulan, MetodeTransaksi $metode): Transaksi
{
    $tagihan = Tagihan::whereHas('pendaftaran', fn ($q) => $q->where('siswa_id', $siswa->id))
        ->whereIn('bulan_tagihan', array_map(fn ($b) => "{$b}-01", $bulan))->get();

    return app(TransaksiService::class)->catat($siswa, $metode, $tagihan->sum(fn (Tagihan $t) => $t->sisa()), $tagihan->pluck('id')->all(), test()->tu);
}

test('lembar cetak hanya berisi transaksi cash, lengkap dengan kelas, kegiatan & bulan, dan total', function () {
    bayarUntukCetak($this->siswa['Ani Cash'], ['2026-08', '2026-09'], MetodeTransaksi::Cash);
    $transfer = bayarUntukCetak($this->siswa['Budi Transfer'], ['2026-08'], MetodeTransaksi::Transfer);
    app(TransaksiService::class)->tolak($transfer, $this->tu, 'Belum masuk rekening');
    bayarUntukCetak($this->siswa['Budi Transfer'], ['2026-09'], MetodeTransaksi::Cash);
    bayarUntukCetak($this->siswa['Budi Transfer'], ['2026-08'], MetodeTransaksi::Transfer);

    $laporan = LaporanHarian::sole();

    $this->actingAs($this->tu)
        ->get("/les-ekskul/laporan-harian/{$laporan->id}/cetak-cash")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('les-ekskul/laporan-harian/cetak-cash')
            ->where('laporan.tanggal', '2026-08-10')
            ->has('transaksi', 2)
            ->where('transaksi.0.siswa', 'Ani Cash')
            ->where('transaksi.0.kelas', '3A')
            ->where('transaksi.0.nominal', 160000)
            ->where('transaksi.0.alokasi', [['kegiatan' => 'Basket', 'bulan' => ['2026-08', '2026-09']]])
            ->where('transaksi.1.siswa', 'Budi Transfer')
            ->where('transaksi.1.kelas', '4B')
            ->where('total', 240000));
});

test('Bendahara dan Kepala Sekolah boleh membuka lembar cetak, orang tua tidak', function () {
    bayarUntukCetak($this->siswa['Ani Cash'], ['2026-08'], MetodeTransaksi::Cash);
    $url = '/les-ekskul/laporan-harian/'.LaporanHarian::sole()->id.'/cetak-cash';

    $this->actingAs(User::factory()->create()->assignRole('bendahara'))->get($url)->assertOk();
    $this->actingAs(User::factory()->create()->assignRole('kepala_sekolah'))->get($url)->assertOk();
    $this->actingAs(User::factory()->create(['email' => null, 'no_hp' => '081234567890'])->assignRole('orang_tua'))->get($url)->assertForbidden();
});
