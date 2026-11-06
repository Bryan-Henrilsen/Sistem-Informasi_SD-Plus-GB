<?php

use App\Domains\LesEkskul\Enums\JenisPenyesuaian;
use App\Domains\LesEkskul\Enums\MetodeTransaksi;
use App\Domains\LesEkskul\Enums\StatusApprovalBendahara;
use App\Domains\LesEkskul\Enums\StatusLaporanHarian;
use App\Domains\LesEkskul\Enums\StatusPenyesuaian;
use App\Domains\LesEkskul\Enums\StatusTagihan;
use App\Domains\LesEkskul\Enums\StatusTransaksi;
use App\Domains\LesEkskul\Enums\SumberInput;
use App\Domains\LesEkskul\Models\Ekstrakurikuler;
use App\Domains\LesEkskul\Models\LaporanHarian;
use App\Domains\LesEkskul\Models\PembayaranOnline;
use App\Domains\LesEkskul\Models\Pendaftaran;
use App\Domains\LesEkskul\Models\PenyesuaianDana;
use App\Domains\LesEkskul\Models\PeriodeLaporan;
use App\Domains\LesEkskul\Models\Tagihan;
use App\Domains\LesEkskul\Models\Transaksi;
use App\Domains\LesEkskul\Services\TagihanService;
use App\Domains\LesEkskul\Services\TransaksiService;
use App\Models\Siswa;
use App\Models\TahunAjaran;
use App\Models\User;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->travelTo('2026-09-10 09:00'); // Kamis

    $this->service = app(TransaksiService::class);
    $this->tu = User::factory()->create();
    $this->siswa = Siswa::create(['nama' => 'Siswa Uji']);

    $tahunAjaran = TahunAjaran::create([
        'nama' => '2026/2027',
        'tanggal_mulai' => '2026-07-01',
        'tanggal_selesai' => '2027-06-30',
        'status_aktif' => true,
    ]);
    $basket = Ekstrakurikuler::create(['tahun_ajaran_id' => $tahunAjaran->id, 'nama' => 'Basket', 'biaya' => 80000]);
    $this->pendaftaran = Pendaftaran::create([
        'siswa_id' => $this->siswa->id,
        'ekstrakurikuler_id' => $basket->id,
        'tanggal_daftar' => '2026-07-15',
    ]);

    $this->periode = PeriodeLaporan::create(['bulan' => '2026-09-01', 'tanggal_mulai' => '2026-08-23', 'tanggal_selesai' => '2026-09-22']);
    [$this->agu, $this->sep, $this->okt] = app(TagihanService::class)->buatSampai($this->pendaftaran, '2026-10-01')->all();
});

// Catat transaksi oleh TU untuk siswa di beforeEach; argumen lain (sumber, tanggal, catatanTransfer) lewat $lain
function catatTransaksi(string $metode, int $nominal, array $tagihan, array $lain = []): Transaksi
{
    // Argumen bernama dikumpulkan di array lalu di-unpack, karena PHP melarang ...$lain setelah argumen bernama
    return test()->service->catat(...[
        'siswa' => test()->siswa,
        'metode' => MetodeTransaksi::from($metode),
        'nominal' => $nominal,
        'tagihanIds' => collect($tagihan)->pluck('id')->all(),
        'oleh' => test()->tu,
        ...$lain,
    ]);
}

// Alokasi sebuah transaksi per bulan tagihan, mis. ['2026-10' => 80000]
function alokasiPerBulan(Transaksi $transaksi): array
{
    return $transaksi->tagihan->sortBy('bulan_tagihan')
        ->mapWithKeys(fn (Tagihan $t) => [$t->bulan_tagihan->format('Y-m') => $t->pivot->nominal])
        ->all();
}

test('cash melunasi tagihan, langsung terverifikasi, dan membuka laporan harian hari ini', function () {
    $transaksi = catatTransaksi('cash', 80000, [$this->sep]);

    expect($transaksi->status)->toBe(StatusTransaksi::Terverifikasi)
        ->and($transaksi->diverifikasi_oleh)->toBe($this->tu->id)
        ->and($transaksi->status_approval_bendahara)->toBeNull()
        ->and($transaksi->laporanHarian->tanggal->toDateString())->toBe('2026-09-10')
        ->and($transaksi->laporanHarian->status)->toBe(StatusLaporanHarian::Draft)
        ->and($this->sep->fresh()->status)->toBe(StatusTagihan::Lunas);
});

test('transfer dari TU menunggu verifikasi, tetapi tagihannya langsung lunas', function () {
    $transaksi = catatTransaksi('transfer', 80000, [$this->sep], ['catatanTransfer' => 'a.n. Ibu Budi']);

    expect($transaksi->status)->toBe(StatusTransaksi::Pending)
        ->and($transaksi->status_approval_bendahara)->toBe(StatusApprovalBendahara::BelumDicek)
        ->and($transaksi->diverifikasi_oleh)->toBeNull()
        ->and($transaksi->catatan_transfer)->toBe('a.n. Ibu Budi')
        ->and($this->sep->fresh()->status)->toBe(StatusTagihan::Lunas);
});

test('uang kurang dibagi mulai bulan terlama, sisanya dibayar transaksi berikutnya', function () {
    catatTransaksi('transfer', 120000, [$this->sep, $this->agu]); // urutan centang tidak berpengaruh

    expect($this->agu->fresh()->status)->toBe(StatusTagihan::Lunas)
        ->and($this->sep->fresh()->status)->toBe(StatusTagihan::Belum)
        ->and($this->sep->sisa())->toBe(40000);

    catatTransaksi('cash', 40000, [$this->sep]);

    expect($this->sep->fresh()->status)->toBe(StatusTagihan::Lunas);
});

test('kelebihan transfer mengurangi tagihan bulan berikutnya, dibuat bila belum ada', function () {
    $transaksi = catatTransaksi('transfer', 120000, [$this->okt]);

    expect(alokasiPerBulan($transaksi))->toBe(['2026-10' => 80000, '2026-11' => 40000])
        ->and(Tagihan::where('bulan_tagihan', '2026-11-01')->first()->status)->toBe(StatusTagihan::Belum)
        ->and(PenyesuaianDana::count())->toBe(0); // pengalihan cukup tercatat di alokasi
});


test('kelebihan yang melewati akhir semester dicatat sebagai refund', function () {
    $transaksi = catatTransaksi('transfer', 200000, [$this->okt]); // Oktober, November, lalu Desember libur

    $refund = $transaksi->penyesuaianDana()->sole();

    expect(alokasiPerBulan($transaksi))->toBe(['2026-10' => 80000, '2026-11' => 80000])
        ->and(Tagihan::where('bulan_tagihan', '2027-01-01')->exists())->toBeFalse() // tidak menyeberang ke semester genap
        ->and($refund->nominal)->toBe(40000)
        ->and($refund->jenis_penyesuaian)->toBe(JenisPenyesuaian::KelebihanTransfer)
        ->and($refund->status)->toBe(StatusPenyesuaian::Proses)
        ->and($refund->pendaftaran_id)->toBe($this->pendaftaran->id)
        ->and($refund->periode_asal_id)->toBe($this->periode->id)
        ->and($refund->adalahRefund())->toBeTrue();
});

test('siswa sudah berhenti: kelebihan langsung direfund', function () {
    $this->pendaftaran->update(['status' => 'berhenti', 'tanggal_efektif_berhenti' => '2026-10-15']);

    $transaksi = catatTransaksi('transfer', 100000, [$this->okt]); // Oktober masih ditagih, November tidak

    expect(alokasiPerBulan($transaksi))->toBe(['2026-10' => 80000])
        ->and($transaksi->penyesuaianDana()->sole()->nominal)->toBe(20000);
});

test('cash tidak boleh melebihi sisa tagihan yang dipilih', function () {
    expect(fn () => catatTransaksi('cash', 100000, [$this->sep]))->toThrow(ValidationException::class);
});

test('tagihan yang sudah lunas, milik siswa lain, atau sedang dibayar online tidak bisa dipilih', function () {
    catatTransaksi('cash', 80000, [$this->agu]);

    $order = PembayaranOnline::create([
        'order_id' => 'SDP-000001',
        'siswa_id' => $this->siswa->id,
        'dibuat_oleh' => $this->tu->id,
        'nominal' => 80000,
        'biaya_admin' => 4000,
        'kedaluwarsa_at' => now()->addDay(),
    ]);
    $order->tagihan()->attach($this->okt->id, ['nominal' => 80000]);

    $siswaLain = Siswa::create(['nama' => 'Siswa Lain']);

    expect(fn () => catatTransaksi('cash', 80000, [$this->agu]))->toThrow(ValidationException::class)
        ->and(fn () => catatTransaksi('cash', 80000, [$this->okt]))->toThrow(ValidationException::class)
        ->and(fn () => $this->service->catat(
            siswa: $siswaLain, metode: MetodeTransaksi::Cash, nominal: 80000, tagihanIds: [$this->sep->id], oleh: $this->tu,
        ))->toThrow(ValidationException::class);
});

test('laporan hari ini sudah disetujui: transaksi masuk laporan hari kerja berikutnya', function () {
    $this->travelTo('2026-09-11 15:00'); // Jumat
    LaporanHarian::create([
        'periode_laporan_id' => $this->periode->id,
        'tanggal' => '2026-09-11',
        'status' => 'disetujui',
        'disetujui_oleh' => $this->tu->id,
        'disetujui_at' => now(),
    ]);

    $transaksi = catatTransaksi('cash', 80000, [$this->sep]);

    expect($transaksi->laporanHarian->tanggal->toDateString())->toBe('2026-09-14') // Senin
        ->and($transaksi->tanggal->toDateString())->toBe('2026-09-11')
        ->and($transaksi->dicatatTerlambat())->toBeTrue();
});

test('laporan yang sedang diajukan harus ditarik kembali dulu', function () {
    LaporanHarian::create([
        'periode_laporan_id' => $this->periode->id,
        'tanggal' => '2026-09-10',
        'status' => 'diajukan',
        'diajukan_oleh' => $this->tu->id,
        'diajukan_at' => now(),
    ]);

    expect(fn () => catatTransaksi('cash', 80000, [$this->sep]))->toThrow(ValidationException::class);
});

test('transfer yang ditambahkan Bendahara langsung terverifikasi dan disetujui', function () {
    $transaksi = catatTransaksi('transfer', 80000, [$this->agu], [
        'sumber' => SumberInput::Bendahara,
        'tanggal' => '2026-09-01', // retroaktif, sesuai mutasi rekening
    ]);

    expect($transaksi->status)->toBe(StatusTransaksi::Terverifikasi)
        ->and($transaksi->status_approval_bendahara)->toBe(StatusApprovalBendahara::Disetujui)
        ->and($transaksi->diapprove_oleh)->toBe($this->tu->id)
        ->and($transaksi->sumber_input)->toBe(SumberInput::Bendahara)
        ->and($transaksi->laporanHarian->tanggal->toDateString())->toBe('2026-09-10')
        ->and($transaksi->dicatatTerlambat())->toBeTrue();
});
