<?php

use App\Domains\LesEkskul\Enums\StatusKonfirmasiTransfer;
use App\Domains\LesEkskul\Enums\StatusTransaksi;
use App\Domains\LesEkskul\Models\Ekstrakurikuler;
use App\Domains\LesEkskul\Models\KonfirmasiTransfer;
use App\Domains\LesEkskul\Models\LesTambahan;
use App\Domains\LesEkskul\Models\Pendaftaran;
use App\Domains\LesEkskul\Models\PeriodeLaporan;
use App\Domains\LesEkskul\Models\Tagihan;
use App\Domains\LesEkskul\Services\KonfirmasiTransferService;
use App\Models\Kelas;
use App\Models\Siswa;
use App\Models\SiswaKelas;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Support\NomorHp;
use Database\Seeders\RoleSeeder;
use Inertia\Testing\AssertableInertia as Assert;

// Konfirmasi "Saya sudah bayar" dari orang tua untuk transfer manual (FR-15b, Ringkasan v1.11 #41)
beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->travelTo('2026-11-10 09:00');
    config(['les_ekskul.wa_tu' => '0812-1111-2222']);

    $this->tu = User::factory()->create()->assignRole('tu');
    $this->ortu = User::factory()->create(['email' => null, 'no_hp' => '081234567890'])->assignRole('orang_tua');

    $ta = TahunAjaran::create(['nama' => '2026/2027', 'tanggal_mulai' => '2026-07-13', 'tanggal_selesai' => '2027-06-30', 'status_aktif' => true]);
    $ta->semester()->createMany([
        ['urutan' => 1, 'nama' => 'Semester 1', 'tanggal_mulai' => '2026-07-13', 'tanggal_selesai' => '2026-12-31'],
        ['urutan' => 2, 'nama' => 'Semester 2', 'tanggal_mulai' => '2027-01-01', 'tanggal_selesai' => '2027-06-30'],
    ]);
    PeriodeLaporan::create(['bulan' => '2026-11-01', 'tanggal_mulai' => '2026-10-23', 'tanggal_selesai' => '2026-11-22']);

    $kelas = Kelas::create(['tahun_ajaran_id' => $ta->id, 'jenjang' => 2, 'nama_kelas' => '2A']);
    $this->anak = Siswa::create(['nama' => 'Budi Santoso']);
    SiswaKelas::create(['siswa_id' => $this->anak->id, 'kelas_id' => $kelas->id]);
    $this->ortu->anak()->attach($this->anak->id);

    $les = LesTambahan::create(['kelas_id' => $kelas->id, 'biaya' => 50000]);
    $basket = Ekstrakurikuler::create(['tahun_ajaran_id' => $ta->id, 'nama' => 'Basket', 'biaya' => 80000]);
    $buat = function (array $kegiatan, array $bulan) {
        $p = Pendaftaran::create(['siswa_id' => $this->anak->id, 'tanggal_daftar' => '2026-08-01', ...$kegiatan]);

        return collect($bulan)->mapWithKeys(fn (string $b) => [$b => Tagihan::create(['pendaftaran_id' => $p->id, 'bulan_tagihan' => $b.'-01', 'nominal' => $kegiatan['biaya']])]);
    };
    $this->les = $buat(['les_tambahan_id' => $les->id, 'biaya' => 50000], ['2026-09', '2026-10', '2026-11', '2027-01']);
    $this->basket = $buat(['ekstrakurikuler_id' => $basket->id, 'biaya' => 80000], ['2026-10', '2026-11']);

    $this->ajukan = fn (array $tagihan, array $lain = []) => $this->actingAs($this->ortu)->post('/ortu/bayar', [
        'siswa_id' => $this->anak->id,
        'tagihan_ids' => array_map(fn (Tagihan $t) => $t->id, $tagihan),
        'tanggal_transfer' => '2026-11-10',
        ...$lain,
    ]);
});

test('halaman pembayaran: total pas dan usulan berita transfer nama depan + kelas + kegiatan & bulan', function () {
    $pilih = [$this->les['2026-09'], $this->les['2026-10'], $this->basket['2026-10']];

    $this->actingAs($this->ortu)->get('/ortu/bayar?'.http_build_query(['siswa' => $this->anak->id, 'tagihan' => array_map(fn ($t) => $t->id, $pilih)]))
        ->assertInertia(fn (Assert $page) => $page->component('les-ekskul/orang-tua/bayar')
            ->where('total', 50000 + 50000 + 80000)
            ->where('berita', 'Budi 2A Les Sep-Okt, Basket Okt')
            ->has('tagihan', 3)
            ->where('adaWhatsApp', true));

    // Bulan berturut-turut menurut bulan ditagih: November lalu Januari (Desember tidak ditagih)
    expect(app(KonfirmasiTransferService::class)->beritaTransfer($this->anak, collect([$this->les['2026-11'], $this->les['2027-01'], $this->les['2026-09']])))
        ->toBe('Budi 2A Les Sep Nov-Jan');
});

test('Saya sudah bayar: konfirmasi menunggu, tagihan tampil menunggu verifikasi dan tidak bisa dipilih lagi', function () {
    ($this->ajukan)([$this->les['2026-10'], $this->basket['2026-10']], ['nama_pengirim' => 'Santoso'])
        ->assertRedirect('/ortu/tagihan');

    $k = KonfirmasiTransfer::sole();
    expect($k->status)->toBe(StatusKonfirmasiTransfer::Menunggu)
        ->and($k->nominal)->toBe(130000)
        ->and($k->tagihan)->toHaveCount(2);

    $this->actingAs($this->ortu)->get('/ortu/tagihan')->assertInertia(fn (Assert $page) => $page
        ->where('anak.0.konfirmasi.0.status', 'menunggu')
        ->where('anak.0.ringkasan.tunggakan', 50000) // Les September saja; Les & Basket Oktober sudah dikonfirmasi
        ->where('anak.0.konfirmasi.0.whatsapp', fn (string $url) => str_starts_with($url, 'https://wa.me/6281211112222?text=') && str_contains(urldecode($url), 'Rp130.000'))
        ->where('anak.0.tagihan', fn ($daftar) => collect($daftar)->firstWhere('id', $this->basket['2026-10']->id)['status'] === 'menunggu_verifikasi'
            && collect($daftar)->firstWhere('id', $this->basket['2026-10']->id)['bisa_dipilih'] === false
            && collect($daftar)->firstWhere('id', $this->les['2026-09']->id)['bisa_dipilih'] === true));

    // Tagihan yang sama tidak bisa dikonfirmasi dua kali
    ($this->ajukan)([$this->basket['2026-10']])->assertSessionHasErrors('tagihan');
    expect(KonfirmasiTransfer::count())->toBe(1);
});

test('tanggal transfer tidak boleh di masa depan; anak orang lain dianggap tidak ada', function () {
    ($this->ajukan)([$this->les['2026-09']], ['tanggal_transfer' => '2026-11-11'])->assertSessionHasErrors('tanggal_transfer');

    $lain = User::factory()->create(['email' => null, 'no_hp' => '081299998888'])->assignRole('orang_tua');
    $this->actingAs($lain)->get('/ortu/bayar?'.http_build_query(['siswa' => $this->anak->id, 'tagihan' => [$this->les['2026-09']->id]]))->assertNotFound();
    $this->actingAs($lain)->post('/ortu/bayar', ['siswa_id' => $this->anak->id, 'tagihan_ids' => [$this->les['2026-09']->id], 'tanggal_transfer' => '2026-11-10'])
        ->assertNotFound();
    $this->actingAs($this->tu)->get('/ortu/bayar')->assertForbidden();
});

test('orang tua membatalkan konfirmasi yang belum diproses; tagihannya bisa dipilih lagi', function () {
    ($this->ajukan)([$this->les['2026-09']]);
    $k = KonfirmasiTransfer::sole();

    $lain = User::factory()->create(['email' => null, 'no_hp' => '081299998888'])->assignRole('orang_tua');
    $this->actingAs($lain)->delete("/ortu/konfirmasi/{$k->id}")->assertNotFound();

    $this->actingAs($this->ortu)->delete("/ortu/konfirmasi/{$k->id}")->assertSessionHasNoErrors();
    expect($k->fresh()->status)->toBe(StatusKonfirmasiTransfer::Dibatalkan);

    ($this->ajukan)([$this->les['2026-09']])->assertSessionHasNoErrors();
});

test('TU mencatat konfirmasi menjadi transaksi transfer; konfirmasi tertaut dan tidak bisa dicatat dua kali', function () {
    ($this->ajukan)([$this->les['2026-10'], $this->basket['2026-10']], ['tanggal_transfer' => '2026-11-09', 'nama_pengirim' => 'Santoso']);
    $k = KonfirmasiTransfer::sole();

    $this->actingAs($this->tu)->get('/les-ekskul/transaksi')
        ->assertInertia(fn (Assert $page) => $page->has('konfirmasi', 1)->where('konfirmasi.0.nominal', 130000));
    $this->actingAs($this->tu)->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page->where('tugas', fn ($kartu) => collect($kartu)->firstWhere('judul', 'Konfirmasi transfer dari orang tua')['jumlah'] === 1));
    $this->actingAs($this->tu)->get("/les-ekskul/transaksi/catat?konfirmasi={$k->id}")
        ->assertInertia(fn (Assert $page) => $page->where('siswa.id', $this->anak->id)
            ->where('konfirmasi.nominal', 130000)
            ->where('konfirmasi.tanggal_transfer', '2026-11-09'));

    $catat = fn () => $this->actingAs($this->tu)->post('/les-ekskul/transaksi', [
        'siswa_id' => $this->anak->id, 'metode' => 'transfer', 'nominal' => 130000, 'tanggal' => '2026-11-09',
        'tagihan_ids' => [$this->les['2026-10']->id, $this->basket['2026-10']->id], 'konfirmasi_transfer_id' => $k->id,
    ]);
    $catat()->assertSessionHasNoErrors();

    $k->refresh();
    expect($k->status)->toBe(StatusKonfirmasiTransfer::Dicatat)
        ->and($k->transaksi->status)->toBe(StatusTransaksi::Pending) // alur verifikasi TU + approval Bendahara tetap
        ->and($k->transaksi->nominal)->toBe(130000)
        ->and($k->diproses_oleh)->toBe($this->tu->id);

    $catat()->assertSessionHasErrors('konfirmasi');
    expect($k->siswa->transaksi()->count())->toBe(1); // ditolak sebelum transaksi kedua dibuat
});

test('TU menolak konfirmasi dengan alasan; orang tua melihat alasannya', function () {
    ($this->ajukan)([$this->les['2026-09']]);
    $k = KonfirmasiTransfer::sole();

    $bendahara = User::factory()->create()->assignRole('bendahara');
    $this->actingAs($bendahara)->post("/les-ekskul/konfirmasi-transfer/{$k->id}/tolak", ['alasan' => 'x'])->assertForbidden();
    $this->actingAs($this->tu)->post("/les-ekskul/konfirmasi-transfer/{$k->id}/tolak", ['alasan' => ''])->assertSessionHasErrors('alasan');
    $this->actingAs($this->tu)->post("/les-ekskul/konfirmasi-transfer/{$k->id}/tolak", ['alasan' => 'Tidak ada di mutasi'])->assertSessionHasNoErrors();

    expect($k->fresh()->status)->toBe(StatusKonfirmasiTransfer::Ditolak);
    $this->actingAs($this->ortu)->get('/ortu/tagihan')->assertInertia(fn (Assert $page) => $page
        ->where('anak.0.konfirmasi.0.status', 'ditolak')
        ->where('anak.0.konfirmasi.0.alasan', 'Tidak ada di mutasi')
        ->where('anak.0.konfirmasi.0.whatsapp', null));

    $this->actingAs($this->ortu)->delete("/ortu/konfirmasi/{$k->id}")->assertSessionHasErrors('konfirmasi'); // sudah diproses
});

test('nomor WhatsApp TU diubah ke format internasional untuk wa.me', function () {
    expect(NomorHp::internasional('0812-1111-2222'))->toBe('6281211112222')
        ->and(NomorHp::internasional('+62 812 1111 2222'))->toBe('6281211112222')
        ->and(NomorHp::internasional(''))->toBeNull();
});
