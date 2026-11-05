<?php

use App\Domains\LesEkskul\Models\Ekstrakurikuler;
use App\Domains\LesEkskul\Models\LaporanHarian;
use App\Domains\LesEkskul\Models\Pendaftaran;
use App\Domains\LesEkskul\Models\PeriodeLaporan;
use App\Domains\LesEkskul\Models\Tagihan;
use App\Domains\LesEkskul\Models\Transaksi;
use App\Models\Siswa;
use App\Models\TahunAjaran;
use App\Models\User;
use Illuminate\Database\QueryException;

beforeEach(function () {
    // Waktu dibekukan supaya hasil test tidak bergantung pada hari test dijalankan
    $this->travelTo('2026-09-10 09:00');

    $this->tu = User::factory()->create();

    $tahunAjaran = TahunAjaran::create([
        'nama' => '2026/2027',
        'tanggal_mulai' => '2026-07-01',
        'tanggal_selesai' => '2027-06-30',
        'status_aktif' => true,
    ]);

    $ekskul = Ekstrakurikuler::create(['tahun_ajaran_id' => $tahunAjaran->id, 'nama' => 'English Club', 'biaya' => 100000]);

    $this->pendaftaran = Pendaftaran::create([
        'siswa_id' => Siswa::create(['nama' => 'Siswa Uji'])->id,
        'ekstrakurikuler_id' => $ekskul->id,
        'tanggal_daftar' => '2026-07-15',
    ]);

    $periode = PeriodeLaporan::create(['bulan' => '2026-09-01', 'tanggal_mulai' => '2026-08-23', 'tanggal_selesai' => '2026-09-22']);

    $this->laporanHarian = LaporanHarian::create(['periode_laporan_id' => $periode->id, 'tanggal' => today()]);
});

// Tagihan untuk pendaftaran di beforeEach; cukup tulis bulan dan kolom yang berbeda
function buatTagihan(string $bulan, array $kolom = []): Tagihan
{
    return Tagihan::create([
        'pendaftaran_id' => test()->pendaftaran->id,
        'bulan_tagihan' => $bulan,
        'nominal' => 100000,
        ...$kolom,
    ]);
}

// Mencatat transaksi cash oleh TU lalu mengalokasikannya ke tagihan, seperti yang nanti dikerjakan Service
function bayarCash(int $nominal, array $alokasi, ?string $tanggal = null): Transaksi
{
    $tu = test()->tu;

    $transaksi = Transaksi::create([
        'siswa_id' => test()->pendaftaran->siswa_id,
        'laporan_harian_id' => test()->laporanHarian->id,
        'tanggal' => $tanggal ?? today(),
        'metode' => 'cash',
        'nominal' => $nominal,
        'status' => 'terverifikasi',
        'diinput_oleh' => $tu->id,
        'diverifikasi_oleh' => $tu->id,
        'diverifikasi_at' => now(),
    ]);

    // [tagihan_id => nominal] menjadi baris alokasi_transaksi
    foreach ($alokasi as $tagihanId => $nominalAlokasi) {
        $transaksi->tagihan()->attach($tagihanId, ['nominal' => $nominalAlokasi]);
    }

    return $transaksi;
}

it('menghitung sisa tagihan setelah pembayaran sebagian', function () {
    $tagihan = buatTagihan('2026-09-01', ['diskon' => 50000]);

    bayarCash(30000, [$tagihan->id => 30000]);

    expect($tagihan->harus_dibayar)->toBe(50000)
        ->and($tagihan->sisa())->toBe(20000);
});

it('melunasi beberapa bulan dengan satu transaksi', function () {
    $september = buatTagihan('2026-09-01');
    $oktober = buatTagihan('2026-10-01');

    $transaksi = bayarCash(200000, [$september->id => 100000, $oktober->id => 100000]);

    expect($transaksi->tagihan)->toHaveCount(2)
        ->and($september->sisa())->toBe(0)
        ->and($oktober->sisa())->toBe(0);
});

it('hanya menghitung tunggakan dari bulan yang sudah lewat', function () {
    buatTagihan('2026-07-01', ['status' => 'lunas']);
    buatTagihan('2026-08-01');
    buatTagihan('2026-09-01');

    expect(Tagihan::tunggakan()->pluck('bulan_tagihan')->map->toDateString()->all())->toBe(['2026-08-01']);
});

it('menandai transaksi yang dicatat terlambat', function () {
    $tepatWaktu = bayarCash(100000, []);
    $terlambat = bayarCash(100000, [], '2026-09-05');

    expect($tepatWaktu->dicatatTerlambat())->toBeFalse()
        ->and($terlambat->dicatatTerlambat())->toBeTrue();
});

it('menemukan periode laporan sampai hari terakhirnya', function () {
    $this->travelTo('2026-09-22 15:30');

    expect(PeriodeLaporan::mencakup(now())->first()->bulan->toDateString())->toBe('2026-09-01');
});

it('menolak periode laporan yang beririsan', function () {
    PeriodeLaporan::create(['bulan' => '2026-10-01', 'tanggal_mulai' => '2026-09-22', 'tanggal_selesai' => '2026-10-22']);
})->throws(QueryException::class, 'periode_laporan_tidak_beririsan');

it('menolak transaksi TU tanpa penginput', function () {
    Transaksi::create([
        'siswa_id' => $this->pendaftaran->siswa_id,
        'laporan_harian_id' => $this->laporanHarian->id,
        'tanggal' => today(),
        'metode' => 'cash',
        'nominal' => 100000,
        'status' => 'terverifikasi',
    ]);
})->throws(QueryException::class, 'transaksi_diinput_oleh_valid');

it('mengizinkan transaksi dari sistem tanpa penginput', function () {
    $transaksi = Transaksi::create([
        'siswa_id' => $this->pendaftaran->siswa_id,
        'laporan_harian_id' => $this->laporanHarian->id,
        'tanggal' => today(),
        'metode' => 'online',
        'nominal' => 100000,
        'status' => 'terverifikasi',
        'sumber_input' => 'sistem',
    ]);

    expect($transaksi->diinput_oleh)->toBeNull();
});
