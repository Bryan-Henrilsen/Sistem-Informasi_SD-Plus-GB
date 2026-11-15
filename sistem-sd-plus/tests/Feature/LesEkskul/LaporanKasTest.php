<?php

use App\Domains\LesEkskul\Enums\KategoriKas;
use App\Domains\LesEkskul\Models\Ekstrakurikuler;
use App\Domains\LesEkskul\Models\KasManual;
use App\Domains\LesEkskul\Models\Pendaftaran;
use App\Domains\LesEkskul\Models\PeriodeLaporan;
use App\Domains\LesEkskul\Models\Tagihan;
use App\Domains\LesEkskul\Models\Transaksi;
use App\Domains\LesEkskul\Services\HonorService;
use App\Domains\LesEkskul\Services\LaporanKasService;
use App\Models\Siswa;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/Pendukung/HonorUji.php';

beforeEach(function () {
    hhSetup($this);
});

function lkCatat(string $kategori, int $nominal, ?string $uraian = null, ?PeriodeLaporan $periode = null)
{
    $periode ??= test()->periode;

    return test()->actingAs(test()->bendahara)->post("/les-ekskul/laporan-kas/{$periode->id}", [
        'kategori' => $kategori, 'nominal' => $nominal, 'tanggal' => '2026-08-25', 'uraian' => $uraian,
    ]);
}

// Bayar di muka: uang September dicatat di laporan harian Agustus, belum diakui
function lkTitipan(int $nominal): void
{
    $ekskul = Ekstrakurikuler::where('nama', 'Basket')->first();
    $siswa = Siswa::create(['nama' => 'Siswa Titip']);
    $pendaftaran = Pendaftaran::create(['siswa_id' => $siswa->id, 'ekstrakurikuler_id' => $ekskul->id, 'tanggal_daftar' => '2026-07-20']);
    $tagihan = Tagihan::create(['pendaftaran_id' => $pendaftaran->id, 'bulan_tagihan' => '2026-09-01', 'nominal' => $nominal]);
    $transaksi = Transaksi::create([
        'siswa_id' => $siswa->id, 'laporan_harian_id' => test()->laporan->id, 'tanggal' => '2026-08-10', 'metode' => 'cash', 'nominal' => $nominal,
        'status' => 'terverifikasi', 'diinput_oleh' => test()->tu->id, 'diverifikasi_oleh' => test()->tu->id, 'diverifikasi_at' => now(),
    ]);
    $transaksi->tagihan()->attach($tagihan->id, ['nominal' => $nominal]);
}

function lkBaris(array $bagian): array
{
    return collect($bagian['baris'])->mapWithKeys(fn (array $b) => [$b['uraian'] => $b['masuk'] - $b['keluar']])->all();
}

test('laporan kas memakai uang riil: kas 20% penuh, hanya pendamping tambahan yang keluar dari kas', function () {
    hhSiapkanReferensi();
    lkTitipan(80000);
    app(HonorService::class)->hitung($this->periode);

    lkCatat('saldo_awal_sekolah', 500000)->assertSessionHasNoErrors();
    lkCatat('pembayaran_kolam', 1290000)->assertSessionHasNoErrors();
    lkCatat('atk', 650000)->assertSessionHasNoErrors();

    $laporan = app(LaporanKasService::class)->susun($this->periode);

    expect(lkBaris($laporan['sekolah']))->toBe([
        'Kas dari Ekstra' => 3096000, // 20% dari 15.480.000 (sudah tanpa uang kolam)
        'Kas dari Les' => 283590, // 6,85% dari 4.140.000
        'Uang kolam masuk (bagian non-honor)' => 1920000,
        'Honor Pramuka' => -400000,
        'Honor Pembuat Soal Les' => -120000,
        'Pendamping tambahan (dari kas)' => -138000, // pendamping kedua Basket
        'Saldo awal kas sekolah' => 500000,
        'Pembayaran uang kolam' => -1290000,
        'ATK' => -650000,
    ])
        ->and($laporan['sekolah']['saldo'])->toBe(3201590)
        ->and(lkBaris($laporan['yayasan']))->toBe(['Kas Yayasan dari uang les' => 1242000])
        ->and($laporan['titipan'])->toBe(80000)
        ->and($laporan['saldo_total'])->toBe(3201590 + 1242000 + 80000)
        ->and($laporan['periode_belum_lengkap'])->toBe([]);
});

test('saldo kas sekolah dan yayasan dibawa ke periode berikutnya; titipan tidak dibawa', function () {
    hhSiapkanReferensi();
    lkTitipan(80000);
    app(HonorService::class)->hitung($this->periode);
    lkCatat('setor_yayasan', 1000000)->assertSessionHasNoErrors();

    $september = PeriodeLaporan::create(['bulan' => '2026-09-01', 'tanggal_mulai' => '2026-08-23', 'tanggal_selesai' => '2026-09-22', 'status' => 'ditutup']);
    $layanan = app(LaporanKasService::class);
    $agustus = $layanan->susun($this->periode);

    // Honor September belum dihitung (masih ada honor flat): saldo dibawa, angka kegiatan belum muncul
    $belum = $layanan->susun($september);
    expect($belum['siap'])->toBeFalse()
        ->and($belum['sekolah']['saldo_lalu'])->toBe($agustus['sekolah']['saldo'])
        ->and($belum['yayasan']['saldo_lalu'])->toBe(242000)
        ->and($belum['titipan'])->toBe(0);

    app(HonorService::class)->hitung($september);
    $siap = $layanan->susun($september);
    expect($siap['sekolah']['saldo'])->toBe($agustus['sekolah']['saldo'] - 520000); // hanya honor flat bulan ini

    // Saldo awal hanya di periode pertama
    lkCatat('saldo_awal_sekolah', 100000, null, $september)->assertSessionHasErrors('kategori');
});

test('periode lalu yang honornya belum dihitung ditandai, bukan ditebak', function () {
    $basket = hhEkskul('Basket');
    hhPendapatan($basket, 800000); // belum ada pengajar: honor tertahan
    $september = PeriodeLaporan::create(['bulan' => '2026-09-01', 'tanggal_mulai' => '2026-08-23', 'tanggal_selesai' => '2026-09-22']);

    expect(app(LaporanKasService::class)->susun($september)['periode_belum_lengkap'])->toBe(['2026-08-01']);
});

test('catatan kas: uraian wajib untuk lainnya, hanya Bendahara & Kepala Sekolah, terkunci setelah Sudah Diketahui', function () {
    lkCatat('pengeluaran_lain', 50000)->assertSessionHasErrors('uraian');
    lkCatat('pengeluaran_lain', 50000, 'Fotokopi formulir')->assertSessionHasNoErrors();
    $this->actingAs($this->tu)->post("/les-ekskul/laporan-kas/{$this->periode->id}", ['kategori' => 'atk', 'nominal' => 1000, 'tanggal' => '2026-08-25'])->assertForbidden();

    $kas = KasManual::first();
    expect($kas->kategori)->toBe(KategoriKas::PengeluaranLain)->and($kas->nilai())->toBe(-50000);

    app(HonorService::class)->tandaiDiketahui($this->periode, $this->kepsek); // periode tanpa honor boleh langsung ditandai
    lkCatat('atk', 1000)->assertSessionHasErrors('periode');
    $this->actingAs($this->kepsek)->delete("/les-ekskul/kas-manual/{$kas->id}")->assertSessionHasErrors('periode');
    expect(KasManual::count())->toBe(1);
});

test('halaman laporan kas bisa dilihat TU tanpa hak mencatat', function () {
    $this->actingAs($this->tu)->get('/les-ekskul/laporan-kas')->assertInertia(fn (Assert $page) => $page
        ->component('les-ekskul/laporan-kas')
        ->where('periode.id', $this->periode->id)
        ->where('periode.pertama', true)
        ->where('bisaCatat', false)
        ->where('laporan.siap', true)
    );
});
