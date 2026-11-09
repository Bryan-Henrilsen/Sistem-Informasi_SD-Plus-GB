<?php

use App\Domains\LesEkskul\Enums\StatusApprovalBendahara;
use App\Domains\LesEkskul\Enums\StatusTagihan;
use App\Domains\LesEkskul\Enums\StatusTransaksi;
use App\Domains\LesEkskul\Models\Ekstrakurikuler;
use App\Domains\LesEkskul\Models\Pendaftaran;
use App\Domains\LesEkskul\Models\PeriodeLaporan;
use App\Domains\LesEkskul\Models\Tagihan;
use App\Domains\LesEkskul\Models\Transaksi;
use App\Models\Siswa;
use App\Models\TahunAjaran;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->travelTo('2026-08-20 09:00'); // Kamis, di dalam periode Agustus

    $this->tu = User::factory()->create()->assignRole('tu');
    $this->bendahara = User::factory()->create()->assignRole('bendahara');

    $tahunAjaran = TahunAjaran::create(['nama' => '2026/2027', 'tanggal_mulai' => '2026-07-13', 'tanggal_selesai' => '2027-06-30', 'status_aktif' => true]);
    $basket = Ekstrakurikuler::create(['tahun_ajaran_id' => $tahunAjaran->id, 'nama' => 'Basket', 'biaya' => 80000]);
    $this->siswa = Siswa::create(['nama' => 'Budi Uji']);
    $this->pendaftaran = Pendaftaran::create(['siswa_id' => $this->siswa->id, 'ekstrakurikuler_id' => $basket->id, 'tanggal_daftar' => '2026-07-15']);
    PeriodeLaporan::create(['bulan' => '2026-08-01', 'tanggal_mulai' => '2026-07-23', 'tanggal_selesai' => '2026-08-22']);
});

test('tamu diarahkan ke login dan orang tua tidak boleh membuka halaman keuangan', function () {
    $this->get('/les-ekskul/tagihan')->assertRedirect('/login');

    $ortu = User::factory()->create()->assignRole('orang_tua');
    $this->actingAs($ortu)->get('/les-ekskul/tagihan')->assertForbidden();
});

test('TU membuka periode berikutnya dan tagihan bulan itu langsung dibuat', function () {
    $this->actingAs($this->tu)
        ->post('/les-ekskul/periode', ['tanggal_selesai' => '2026-09-22'])
        ->assertRedirect('/les-ekskul/periode');

    $periode = PeriodeLaporan::where('bulan', '2026-09-01')->sole();

    expect($periode->tanggal_mulai->toDateString())->toBe('2026-08-23') // sehari setelah periode Agustus
        ->and(Tagihan::where('bulan_tagihan', '2026-09-01')->count())->toBe(1);

    $this->actingAs($this->tu)->get('/les-ekskul/periode')
        ->assertInertia(fn (Assert $page) => $page->component('les-ekskul/periode/index')
            ->has('periode', 2)
            ->where('usulan.bulan', '2026-10-01'));
});

test('tanggal selesai periode harus di bulan periode itu', function () {
    $this->actingAs($this->tu)
        ->post('/les-ekskul/periode', ['tanggal_selesai' => '2026-10-22'])
        ->assertSessionHasErrors('tanggal_selesai');
});

test('halaman tagihan menampilkan kartu iuran per bulan', function () {
    $this->actingAs($this->tu)->post('/les-ekskul/periode', ['tanggal_selesai' => '2026-09-22']);

    $this->actingAs($this->tu)->get('/les-ekskul/tagihan')
        ->assertInertia(fn (Assert $page) => $page->component('les-ekskul/tagihan/index')
            ->has('pendaftaran.data', 1)
            ->where('pendaftaran.data.0.bulan.2026-09.sisa', 80000)
            ->where('bulan.0', '2026-08'));
});

test('TU mencatat transfer dari halaman, lalu Bendahara menyetujui setelah TU memverifikasi', function () {
    $this->actingAs($this->tu)->post('/les-ekskul/tagihan/bayar-di-muka', ['pendaftaran_id' => $this->pendaftaran->id]);
    $tagihan = Tagihan::sole();

    $this->actingAs($this->tu)
        ->post('/les-ekskul/transaksi', [
            'siswa_id' => $this->siswa->id,
            'metode' => 'transfer',
            'nominal' => 80000,
            'tanggal' => '2026-08-20',
            'tagihan_ids' => [$tagihan->id],
            'catatan_transfer' => 'a.n. Ibu Budi',
        ])
        ->assertRedirect('/les-ekskul/transaksi');

    $transaksi = Transaksi::sole();
    expect($transaksi->status)->toBe(StatusTransaksi::Pending)
        ->and($tagihan->fresh()->status)->toBe(StatusTagihan::Lunas);

    $this->actingAs($this->bendahara)->post("/les-ekskul/transaksi/{$transaksi->id}/verifikasi")->assertForbidden(); // hanya TU
    $this->actingAs($this->tu)->post("/les-ekskul/transaksi/{$transaksi->id}/verifikasi")->assertRedirect();
    $this->actingAs($this->bendahara)->post("/les-ekskul/transaksi/{$transaksi->id}/setujui")->assertRedirect();

    expect($transaksi->fresh()->status_approval_bendahara)->toBe(StatusApprovalBendahara::Disetujui);
});

test('form catat pembayaran menolak input yang tidak lengkap', function () {
    $this->actingAs($this->tu)
        ->post('/les-ekskul/transaksi', ['siswa_id' => $this->siswa->id, 'metode' => 'online', 'nominal' => 0])
        ->assertSessionHasErrors(['metode', 'nominal', 'tanggal', 'tagihan_ids']);
});

test('penolakan transfer wajib beralasan', function () {
    $this->actingAs($this->tu)->post('/les-ekskul/tagihan/bayar-di-muka', ['pendaftaran_id' => $this->pendaftaran->id]);
    $this->actingAs($this->tu)->post('/les-ekskul/transaksi', [
        'siswa_id' => $this->siswa->id, 'metode' => 'transfer', 'nominal' => 80000,
        'tanggal' => '2026-08-20', 'tagihan_ids' => [Tagihan::sole()->id],
    ]);
    $transaksi = Transaksi::sole();

    $this->actingAs($this->bendahara)->post("/les-ekskul/transaksi/{$transaksi->id}/tolak", ['alasan' => ''])
        ->assertSessionHasErrors('alasan');
    $this->actingAs($this->bendahara)->post("/les-ekskul/transaksi/{$transaksi->id}/tolak", ['alasan' => 'Tidak ada di mutasi'])
        ->assertRedirect();

    expect($transaksi->fresh()->status)->toBe(StatusTransaksi::Ditolak);
});

test('halaman catat pembayaran menampilkan tagihan terbuka siswa yang dipilih', function () {
    $this->actingAs($this->tu)->post('/les-ekskul/tagihan/bayar-di-muka', ['pendaftaran_id' => $this->pendaftaran->id]);

    $this->actingAs($this->tu)->get("/les-ekskul/transaksi/catat?siswa={$this->siswa->id}")
        ->assertInertia(fn (Assert $page) => $page->component('les-ekskul/transaksi/create')
            ->where('siswa.nama', 'Budi Uji')
            ->has('tagihan', 1)
            ->where('tagihan.0.sisa', 80000));

    $this->actingAs($this->tu)->get('/les-ekskul/transaksi')
        ->assertInertia(fn (Assert $page) => $page->component('les-ekskul/transaksi/index'));
});
