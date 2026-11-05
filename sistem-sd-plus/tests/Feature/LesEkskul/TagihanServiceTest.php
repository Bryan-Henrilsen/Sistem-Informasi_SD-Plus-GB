<?php

use App\Domains\LesEkskul\Models\Ekstrakurikuler;
use App\Domains\LesEkskul\Models\LesTambahan;
use App\Domains\LesEkskul\Models\Pendaftaran;
use App\Domains\LesEkskul\Models\PeriodeLaporan;
use App\Domains\LesEkskul\Models\Tagihan;
use App\Domains\LesEkskul\Services\TagihanService;
use App\Models\Guru;
use App\Models\Kelas;
use App\Models\Siswa;
use App\Models\TahunAjaran;
use App\Models\User;

beforeEach(function () {
    $this->service = app(TagihanService::class);

    $this->tahunAjaran = TahunAjaran::create([
        'nama' => '2026/2027',
        'tanggal_mulai' => '2026-07-01',
        'tanggal_selesai' => '2027-06-30',
        'status_aktif' => true,
    ]);
    $this->basket = Ekstrakurikuler::create(['tahun_ajaran_id' => $this->tahunAjaran->id, 'nama' => 'Basket', 'biaya' => 80000]);
    $this->siswa = Siswa::create(['nama' => 'Siswa Uji']);
});

// Pendaftaran Basket untuk siswa di beforeEach; kolom yang berbeda cukup ditimpa
function daftarBasket(array $kolom = []): Pendaftaran
{
    return Pendaftaran::create([
        'siswa_id' => test()->siswa->id,
        'ekstrakurikuler_id' => test()->basket->id,
        'tanggal_daftar' => '2026-07-15',
        ...$kolom,
    ]);
}

// Periode laporan dengan pola tanggal 23 bulan lalu sampai 22 bulan ini
function bukaPeriode(string $bulan): PeriodeLaporan
{
    $awal = Carbon\Carbon::parse($bulan);

    return PeriodeLaporan::create([
        'bulan' => $awal->toDateString(),
        'tanggal_mulai' => $awal->copy()->subMonth()->day(23)->toDateString(),
        'tanggal_selesai' => $awal->copy()->day(22)->toDateString(),
    ]);
}

// Daftar bulan tagihan sebuah pendaftaran, mis. ['2026-08', '2026-09']
function bulanTagihan(Pendaftaran $pendaftaran): array
{
    return $pendaftaran->tagihan()->orderBy('bulan_tagihan')->get()
        ->map(fn (Tagihan $t) => $t->bulan_tagihan->format('Y-m'))->all();
}

test('membuka periode menagih pendaftaran aktif dan aman dijalankan ulang', function () {
    $pendaftaran = daftarBasket();
    $periode = bukaPeriode('2026-09-01');

    expect($this->service->buatUntukPeriode($periode))->toBe(1)
        ->and($this->service->buatUntukPeriode($periode))->toBe(0)
        ->and(bulanTagihan($pendaftaran))->toBe(['2026-09']);

    $tagihan = $pendaftaran->tagihan()->first();
    expect($tagihan->nominal)->toBe(80000)
        ->and($tagihan->diskon)->toBe(0);
});

test('juli, desember, dan juni tidak ditagih', function () {
    daftarBasket();

    expect($this->service->buatUntukPeriode(bukaPeriode('2026-07-01')))->toBe(0)
        ->and($this->service->buatUntukPeriode(bukaPeriode('2026-12-01')))->toBe(0)
        ->and($this->service->buatUntukPeriode(bukaPeriode('2027-06-01')))->toBe(0);
});

test('anak guru aktif mendapat diskon 50 persen, guru nonaktif tidak', function () {
    $guruAktif = Guru::create(['user_id' => User::factory()->create()->id, 'kode_guru' => 'GA', 'status_aktif' => true]);
    $guruNonaktif = Guru::create(['user_id' => User::factory()->create()->id, 'kode_guru' => 'GN', 'status_aktif' => false]);
    $anakA = Siswa::create(['nama' => 'Anak A', 'anak_guru_id' => $guruAktif->id]);
    $anakB = Siswa::create(['nama' => 'Anak B', 'anak_guru_id' => $guruNonaktif->id]);

    $tagihanA = $this->service->buatUntukBulan(daftarBasket(['siswa_id' => $anakA->id]), '2026-09-01');
    $tagihanB = $this->service->buatUntukBulan(daftarBasket(['siswa_id' => $anakB->id]), '2026-09-01');

    expect($tagihanA->diskon)->toBe(40000)
        ->and($tagihanA->harus_dibayar)->toBe(40000)
        ->and($tagihanB->diskon)->toBe(0);
});

test('kegiatan berbiaya 0 tidak ditagih', function () {
    $olimpiade = Ekstrakurikuler::create(['tahun_ajaran_id' => $this->tahunAjaran->id, 'nama' => 'Olimpiade MTK', 'biaya' => 0, 'honor_flat' => true]);
    daftarBasket(['ekstrakurikuler_id' => $olimpiade->id]);

    expect($this->service->buatUntukPeriode(bukaPeriode('2026-09-01')))->toBe(0);
});

test('les tambahan ditagih sesuai biaya les kelasnya', function () {
    $kelas = Kelas::create(['tahun_ajaran_id' => $this->tahunAjaran->id, 'jenjang' => 2, 'nama_kelas' => '2A']);
    $les = LesTambahan::create(['kelas_id' => $kelas->id, 'biaya' => 180000]);
    $pendaftaran = daftarBasket(['ekstrakurikuler_id' => null, 'les_tambahan_id' => $les->id]);

    expect($this->service->buatUntukBulan($pendaftaran, '2026-09-01')->nominal)->toBe(180000);
});

test('berhenti di tengah bulan tetap ditagih bulan itu, bulan berikutnya tidak', function () {
    $pendaftaran = daftarBasket(['status' => 'berhenti', 'tanggal_efektif_berhenti' => '2026-10-15']);

    $this->service->buatUntukPeriode(bukaPeriode('2026-10-01'));
    $this->service->buatUntukPeriode(bukaPeriode('2026-11-01'));

    expect(bulanTagihan($pendaftaran))->toBe(['2026-10']);
});

test('ekskul berhenti di pergantian semester ditagih sampai november', function () {
    $pendaftaran = daftarBasket(['status' => 'berhenti', 'tanggal_efektif_berhenti' => '2027-01-01']);

    $this->service->buatSampai($pendaftaran, '2027-03-01');

    expect(bulanTagihan($pendaftaran))->toBe(['2026-08', '2026-09', '2026-10', '2026-11']);
});

test('pendaftar di sela periode ditagih bulan daftar dan bulan periode berjalan', function () {
    bukaPeriode('2026-08-01');
    bukaPeriode('2026-09-01'); // 23 Agu–22 Sep, sudah dibuka

    $pendaftaran = daftarBasket(['tanggal_daftar' => '2026-08-25']);
    $this->service->buatSetelahDaftar($pendaftaran);

    expect(bulanTagihan($pendaftaran))->toBe(['2026-08', '2026-09']);
});

test('bayar di muka berhenti di akhir tahun ajaran', function () {
    $pendaftaran = daftarBasket();

    $this->service->buatSampai($pendaftaran, '2027-08-01');

    expect(bulanTagihan($pendaftaran))->toBe([
        '2026-08', '2026-09', '2026-10', '2026-11',
        '2027-01', '2027-02', '2027-03', '2027-04', '2027-05',
    ]);
});
