<?php

use App\Domains\LesEkskul\Enums\StatusPembayaranOnline;
use App\Domains\LesEkskul\Models\Ekstrakurikuler;
use App\Domains\LesEkskul\Models\LaporanHarian;
use App\Domains\LesEkskul\Models\PembayaranOnline;
use App\Domains\LesEkskul\Models\Pendaftaran;
use App\Domains\LesEkskul\Models\PeriodeLaporan;
use App\Domains\LesEkskul\Models\Tagihan;
use App\Domains\LesEkskul\Models\Transaksi;
use App\Models\Siswa;
use App\Models\TahunAjaran;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->travelTo('2026-09-10 09:00');

    $this->ortu = User::factory()->create();
    $this->siswa = Siswa::create(['nama' => 'Siswa Uji']);

    $tahunAjaran = TahunAjaran::create([
        'nama' => '2026/2027',
        'tanggal_mulai' => '2026-07-01',
        'tanggal_selesai' => '2027-06-30',
        'status_aktif' => true,
    ]);
    $basket = Ekstrakurikuler::create(['tahun_ajaran_id' => $tahunAjaran->id, 'nama' => 'Basket', 'biaya' => 80000]);
    $pendaftaran = Pendaftaran::create([
        'siswa_id' => $this->siswa->id,
        'ekstrakurikuler_id' => $basket->id,
        'tanggal_daftar' => '2026-07-15',
    ]);

    $this->tagihanSep = Tagihan::create(['pendaftaran_id' => $pendaftaran->id, 'bulan_tagihan' => '2026-09-01', 'nominal' => 80000]);
    $this->tagihanOkt = Tagihan::create(['pendaftaran_id' => $pendaftaran->id, 'bulan_tagihan' => '2026-10-01', 'nominal' => 80000]);
});

// Order online untuk tagihan yang dipilih, seperti yang nanti dikerjakan Service (FR-29)
function buatOrderOnline(array $daftarTagihan, array $kolom = []): PembayaranOnline
{
    $order = PembayaranOnline::create([
        'order_id' => 'SDP-'.fake()->unique()->numerify('######'),
        'siswa_id' => test()->siswa->id,
        'dibuat_oleh' => test()->ortu->id,
        'nominal' => collect($daftarTagihan)->sum(fn (Tagihan $t) => $t->sisa()),
        'biaya_admin' => 4000,
        'kedaluwarsa_at' => now()->addDay(),
        ...$kolom,
    ]);

    foreach ($daftarTagihan as $tagihan) {
        $order->tagihan()->attach($tagihan->id, ['nominal' => $tagihan->sisa()]);
    }

    return $order;
}

test('order baru berstatus menunggu dan total bayar dihitung otomatis', function () {
    $order = buatOrderOnline([$this->tagihanSep, $this->tagihanOkt]);

    expect($order->fresh()->status)->toBe(StatusPembayaranOnline::Menunggu)
        ->and($order->total_bayar)->toBe(164000)
        ->and($order->tagihan)->toHaveCount(2)
        ->and($order->tagihan->sum('pivot.nominal'))->toBe($order->nominal);
});

test('tagihan di order menunggu tidak bisa dipilih untuk order lain', function () {
    buatOrderOnline([$this->tagihanSep]);

    expect(Tagihan::bisaDibayarOnline()->pluck('id')->all())->toBe([$this->tagihanOkt->id]);
});

test('tagihan bisa dipilih lagi setelah ordernya kedaluwarsa', function () {
    $order = buatOrderOnline([$this->tagihanSep]);

    $order->update(['status' => StatusPembayaranOnline::Kedaluwarsa]);

    expect(Tagihan::bisaDibayarOnline()->count())->toBe(2);
});

test('order menunggu yang lewat batas waktu terdeteksi', function () {
    buatOrderOnline([$this->tagihanSep]);
    buatOrderOnline([$this->tagihanOkt], ['kedaluwarsa_at' => now()->addDays(3)]);

    $this->travelTo('2026-09-11 10:00');

    expect(PembayaranOnline::lewatBatas()->count())->toBe(1);
});

test('order lunas terhubung ke transaksinya', function () {
    $order = buatOrderOnline([$this->tagihanSep, $this->tagihanOkt]);
    $periode = PeriodeLaporan::create(['bulan' => '2026-09-01', 'tanggal_mulai' => '2026-08-23', 'tanggal_selesai' => '2026-09-22']);
    $transaksi = Transaksi::create([
        'siswa_id' => $this->siswa->id,
        'laporan_harian_id' => LaporanHarian::create(['periode_laporan_id' => $periode->id, 'tanggal' => today()])->id,
        'tanggal' => today(),
        'metode' => 'online',
        'nominal' => $order->nominal,
        'status' => 'terverifikasi',
        'sumber_input' => 'sistem',
    ]);

    $order->update([
        'status' => StatusPembayaranOnline::Lunas,
        'dibayar_at' => now(),
        'transaksi_id' => $transaksi->id,
        'payload_terakhir' => ['order_id' => $order->order_id, 'transaction_status' => 'settlement'],
    ]);

    expect($transaksi->pembayaranOnline->order_id)->toBe($order->order_id)
        ->and($order->fresh()->sudahLunas())->toBeTrue()
        ->and($order->fresh()->payload_terakhir['transaction_status'])->toBe('settlement')
        ->and($this->siswa->pembayaranOnline)->toHaveCount(1);
});

test('database menolak total bayar yang tidak cocok', function () {
    $order = buatOrderOnline([$this->tagihanSep]);

    // Lewat query builder, event saving Model tidak berjalan; CHECK database yang menolak
    DB::table('pembayaran_online')->where('id', $order->id)->update(['total_bayar' => 80000]);
})->throws(QueryException::class, 'pembayaran_online_total_bayar_valid');
