<?php

use App\Domains\LesEkskul\Enums\MetodeTransaksi;
use App\Domains\LesEkskul\Enums\StatusApprovalBendahara;
use App\Domains\LesEkskul\Enums\StatusLaporanHarian;
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

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->travelTo('2026-08-10 09:00'); // Senin

    $this->tu = User::factory()->create()->assignRole('tu');
    $this->bendahara = User::factory()->create()->assignRole('bendahara');

    $ta = TahunAjaran::create(['nama' => '2026/2027', 'tanggal_mulai' => '2026-07-13', 'tanggal_selesai' => '2027-06-30', 'status_aktif' => true]);
    PeriodeLaporan::create(['bulan' => '2026-08-01', 'tanggal_mulai' => '2026-07-23', 'tanggal_selesai' => '2026-08-22']);

    $kelas = Kelas::create(['tahun_ajaran_id' => $ta->id, 'jenjang' => 3, 'nama_kelas' => '3A']);
    $this->siswa = Siswa::create(['nama' => 'Budi Uji']);
    SiswaKelas::create(['siswa_id' => $this->siswa->id, 'kelas_id' => $kelas->id]);

    $basket = Ekstrakurikuler::create(['tahun_ajaran_id' => $ta->id, 'nama' => 'Basket', 'biaya' => 80000]);
    $this->pendaftaran = Pendaftaran::create(['siswa_id' => $this->siswa->id, 'ekstrakurikuler_id' => $basket->id, 'tanggal_daftar' => '2026-07-20']);
    app(TagihanService::class)->buatSampai($this->pendaftaran, '2026-11-01');
});

function bayarBulan(string $bulan, MetodeTransaksi $metode): Transaksi
{
    $tagihan = Tagihan::where('bulan_tagihan', "{$bulan}-01")->sole();

    return app(TransaksiService::class)->catat(test()->siswa, $metode, $tagihan->sisa(), [$tagihan->id], test()->tu);
}

function kartu(array $tugas, string $judul): ?array
{
    return collect($tugas)->firstWhere('judul', $judul);
}

test('beranda TU menampilkan tugasnya dengan tautan ke daftar yang sudah terfilter', function () {
    bayarBulan('2026-08', MetodeTransaksi::Transfer); // 10 Agustus, pending
    $this->travelTo('2026-08-11 09:00');

    $this->actingAs($this->tu)->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page->component('dashboard')
            ->where('tugas', fn ($tugas) => kartu($tugas->all(), 'Transfer perlu diverifikasi')['jumlah'] === 1
                && kartu($tugas->all(), 'Transfer perlu diverifikasi')['href'] === '/les-ekskul/transaksi?status=pending'
                && kartu($tugas->all(), 'Laporan harian belum diajukan')['jumlah'] === 1
                && kartu($tugas->all(), 'Transfer perlu disetujui') === null) // kartu Bendahara tidak tampil untuk TU
            ->where('keuangan.periode.total', 80000));
});

test('beranda Bendahara: transfer terverifikasi perlu disetujui, yang masih pending tampil sebagai info', function () {
    $diverifikasi = bayarBulan('2026-08', MetodeTransaksi::Transfer);
    app(TransaksiService::class)->verifikasi($diverifikasi, $this->tu);
    bayarBulan('2026-09', MetodeTransaksi::Transfer); // masih pending

    $this->actingAs($this->bendahara)->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page
            ->where('tugas', fn ($tugas) => kartu($tugas->all(), 'Transfer perlu disetujui')['jumlah'] === 1
                && kartu($tugas->all(), 'Transfer menunggu verifikasi TU')['nada'] === 'info'
                && kartu($tugas->all(), 'Transfer perlu diverifikasi') === null));
});

test('guru tanpa role keuangan melihat beranda tanpa kartu keuangan dan tanpa badge', function () {
    $guru = User::factory()->create()->assignRole('guru');

    $this->actingAs($guru)->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page->where('tugas', [])->where('keuangan', null)->where('badge', null));
});

test('badge menu menghitung yang menunggu tindakan role pengguna', function () {
    bayarBulan('2026-08', MetodeTransaksi::Transfer);
    $this->travelTo('2026-08-11 09:00');

    $this->actingAs($this->tu)->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page->where('badge', ['transaksi' => 1, 'laporan_harian' => 1, 'refund' => 0]));
    $this->actingAs($this->bendahara)->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page->where('badge', ['transaksi' => 0, 'laporan_harian' => 0, 'refund' => 0]));
});

test('TU mengajukan semua laporan sampai kemarin sekaligus; laporan hari ini tidak ikut', function () {
    bayarBulan('2026-08', MetodeTransaksi::Cash); // 10 Agustus
    $this->travelTo('2026-08-11 09:00');
    bayarBulan('2026-09', MetodeTransaksi::Cash); // 11 Agustus (hari ini)

    $this->actingAs($this->bendahara)->post('/les-ekskul/laporan-harian/ajukan-semua')->assertForbidden();
    $this->actingAs($this->tu)->post('/les-ekskul/laporan-harian/ajukan-semua')->assertSessionHasNoErrors();

    expect(LaporanHarian::orderBy('tanggal')->pluck('status')->all())
        ->toBe([StatusLaporanHarian::Diajukan, StatusLaporanHarian::Draft]);
});

test('Bendahara menyetujui semua transfer terverifikasi di satu laporan; yang masih pending tidak ikut', function () {
    $a = bayarBulan('2026-08', MetodeTransaksi::Transfer);
    $b = bayarBulan('2026-09', MetodeTransaksi::Transfer);
    app(TransaksiService::class)->verifikasi($a, $this->tu);
    $laporan = LaporanHarian::sole();

    $this->actingAs($this->tu)->post("/les-ekskul/laporan-harian/{$laporan->id}/setujui-transfer")->assertForbidden();
    $this->actingAs($this->bendahara)->post("/les-ekskul/laporan-harian/{$laporan->id}/setujui-transfer")->assertSessionHasNoErrors();

    expect($a->fresh()->status_approval_bendahara)->toBe(StatusApprovalBendahara::Disetujui)
        ->and($b->fresh()->status_approval_bendahara)->toBe(StatusApprovalBendahara::BelumDicek);
});

test('mode perlu tindakan menampilkan laporan sesuai role', function () {
    bayarBulan('2026-08', MetodeTransaksi::Transfer); // laporan 10 Agustus: draft, transfer pending

    $jumlah = fn (User $u) => count($this->actingAs($u)->get('/les-ekskul/laporan-harian?tindakan=1')->inertiaProps('laporan'));

    expect($jumlah($this->tu))->toBe(1) // draft + transfer perlu verifikasi
        ->and($jumlah($this->bendahara))->toBe(0); // belum diajukan, transfer belum diverifikasi
});

test('form catat pembayaran Bendahara hanya untuk transfer; TU tetap bisa cash', function () {
    $this->actingAs($this->bendahara)->get('/les-ekskul/transaksi/catat')
        ->assertInertia(fn (Assert $page) => $page->where('hanyaTransfer', true));
    $this->actingAs($this->tu)->get('/les-ekskul/transaksi/catat')
        ->assertInertia(fn (Assert $page) => $page->where('hanyaTransfer', false));
});
