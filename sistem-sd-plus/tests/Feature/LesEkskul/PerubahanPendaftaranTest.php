<?php

use App\Domains\LesEkskul\Enums\JenisPenyesuaian;
use App\Domains\LesEkskul\Enums\MetodeTransaksi;
use App\Domains\LesEkskul\Enums\StatusPendaftaran;
use App\Domains\LesEkskul\Enums\StatusPenyesuaian;
use App\Domains\LesEkskul\Models\Ekstrakurikuler;
use App\Domains\LesEkskul\Models\JadwalEkskul;
use App\Domains\LesEkskul\Models\LesTambahan;
use App\Domains\LesEkskul\Models\Pendaftaran;
use App\Domains\LesEkskul\Models\PenyesuaianDana;
use App\Domains\LesEkskul\Models\PeriodeLaporan;
use App\Domains\LesEkskul\Models\Tagihan;
use App\Domains\LesEkskul\Services\TagihanService;
use App\Domains\LesEkskul\Services\TransaksiService;
use App\Models\Kelas;
use App\Models\Siswa;
use App\Models\SiswaKelas;
use App\Models\TahunAjaran;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->travelTo('2026-10-15 09:00'); // Kamis, di dalam periode Oktober

    $this->tu = User::factory()->create()->assignRole('tu');

    $ta = TahunAjaran::create(['nama' => '2026/2027', 'tanggal_mulai' => '2026-07-13', 'tanggal_selesai' => '2027-06-30', 'status_aktif' => true]);
    $ta->semester()->createMany([
        ['urutan' => 1, 'nama' => 'Semester 1', 'tanggal_mulai' => '2026-07-13', 'tanggal_selesai' => '2026-12-31'],
        ['urutan' => 2, 'nama' => 'Semester 2', 'tanggal_mulai' => '2027-01-01', 'tanggal_selesai' => '2027-06-30'],
    ]);
    foreach (['2026-08', '2026-09', '2026-10', '2026-11'] as $bulan) {
        $awal = CarbonImmutable::parse("{$bulan}-01");
        PeriodeLaporan::create(['bulan' => $awal, 'tanggal_mulai' => $awal->subMonth()->day(23), 'tanggal_selesai' => $awal->day(22)]);
    }

    $kelas = Kelas::create(['tahun_ajaran_id' => $ta->id, 'jenjang' => 3, 'nama_kelas' => '3A']);
    $this->siswa = Siswa::create(['nama' => 'Budi Uji']);
    SiswaKelas::create(['siswa_id' => $this->siswa->id, 'kelas_id' => $kelas->id]);

    $this->les = LesTambahan::create(['kelas_id' => $kelas->id, 'biaya' => 150000]);
    $buat = fn (string $nama, int $biaya, int $hari, string $jam) => tap(
        Ekstrakurikuler::create(['tahun_ajaran_id' => $ta->id, 'nama' => $nama, 'biaya' => $biaya]),
        fn ($e) => JadwalEkskul::create(['ekstrakurikuler_id' => $e->id, 'hari' => $hari, 'jam_mulai' => $jam, 'jam_selesai' => date('H:i', strtotime($jam) + 3600), 'lokasi' => '-']),
    );
    $this->basket = $buat('Basket', 80000, 6, '07:00');
    $this->swimming = $buat('Swimming', 80000, 6, '15:00');
    $this->english = $buat('English Club', 100000, 5, '14:00');
    $this->olimpiade = $buat('Olimpiade MTK', 0, 6, '07:30'); // bentrok dengan Basket
});

// Pendaftaran sejak Juli, tagihan Agustus–November sudah dibuat
function ikut(LesTambahan|Ekstrakurikuler $kegiatan): Pendaftaran
{
    $pendaftaran = Pendaftaran::create([
        'siswa_id' => test()->siswa->id,
        'les_tambahan_id' => $kegiatan instanceof LesTambahan ? $kegiatan->id : null,
        'ekstrakurikuler_id' => $kegiatan instanceof Ekstrakurikuler ? $kegiatan->id : null,
        'tanggal_daftar' => '2026-07-20',
    ]);
    app(TagihanService::class)->buatSampai($pendaftaran, '2026-11-01');

    return $pendaftaran;
}

function bayar(Pendaftaran $pendaftaran, array $bulan, MetodeTransaksi $metode = MetodeTransaksi::Cash): void
{
    $tagihan = $pendaftaran->tagihan()->whereIn('bulan_tagihan', array_map(fn ($b) => "{$b}-01", $bulan))->get();
    app(TransaksiService::class)->catat(test()->siswa, $metode, $tagihan->sum(fn (Tagihan $t) => $t->sisa()), $tagihan->pluck('id')->all(), test()->tu);
}

function statusTagihan(Pendaftaran $pendaftaran): array
{
    return $pendaftaran->tagihan()->orderBy('bulan_tagihan')->get()
        ->mapWithKeys(fn (Tagihan $t) => [$t->bulan_tagihan->format('Y-m') => $t->status->value.':'.$t->sisa()])->all();
}

function ubah(Pendaftaran $pendaftaran, string $aksi, array $data)
{
    return test()->actingAs(test()->tu)->post("/les-ekskul/pendaftaran/{$pendaftaran->id}/{$aksi}", $data);
}

test('les berhenti tengah bulan: bulan itu tetap ditagih, bulan berikutnya yang sudah dibayar direfund', function () {
    $les = ikut($this->les);
    bayar($les, ['2026-10', '2026-11']);

    ubah($les, 'berhenti', ['tanggal' => '2026-10-15'])->assertSessionHasNoErrors();

    expect($les->fresh()->status)->toBe(StatusPendaftaran::Berhenti)
        ->and(statusTagihan($les))->toBe(['2026-08' => 'belum:150000', '2026-09' => 'belum:150000', '2026-10' => 'lunas:0', '2026-11' => 'dibatalkan:150000'])
        ->and(PenyesuaianDana::sole())
        ->jenis_penyesuaian->toBe(JenisPenyesuaian::RefundBerhenti)
        ->nominal->toBe(150000)
        ->status->toBe(StatusPenyesuaian::Proses);
});

test('les berhenti tanggal 1: bulan itu tidak ditagih, tagihan yang belum dibayar dibatalkan tanpa refund', function () {
    $les = ikut($this->les);

    ubah($les, 'berhenti', ['tanggal' => '2026-11-01'])->assertSessionHasNoErrors();

    expect(statusTagihan($les))->toMatchArray(['2026-10' => 'belum:150000', '2026-11' => 'dibatalkan:150000'])
        ->and(PenyesuaianDana::count())->toBe(0);
});

test('ekskul berhenti di luar batas semester perlu konfirmasi dan alasan; di batas semester tidak', function () {
    $basket = ikut($this->basket);

    ubah($basket, 'berhenti', ['tanggal' => '2026-10-20'])->assertSessionHasErrors('konfirmasi');
    ubah($basket, 'berhenti', ['tanggal' => '2026-10-20', 'konfirmasi' => '1'])->assertSessionHasErrors('alasan_berhenti');
    ubah($basket, 'berhenti', ['tanggal' => '2026-10-20', 'konfirmasi' => '1', 'alasan_berhenti' => 'Sakit'])->assertSessionHasNoErrors();
    expect($basket->fresh()->alasan_berhenti)->toBe('Sakit');

    $english = ikut($this->english);
    ubah($english, 'berhenti', ['tanggal' => '2027-01-01'])->assertSessionHasNoErrors(); // pergantian semester

    $swimming = ikut($this->swimming);
    ubah($swimming, 'berhenti', ['tanggal' => '2026-09-01'])->assertSessionHasNoErrors(); // setelah bulan pertama semester (Agustus)
});

test('pindah ke ekskul berbiaya sama: bulan pindah dan seterusnya dialihkan utuh', function () {
    $basket = ikut($this->basket);
    bayar($basket, ['2026-10', '2026-11']);

    ubah($basket, 'pindah', ['ekstrakurikuler_id' => $this->swimming->id, 'tanggal' => '2026-10-15', 'konfirmasi' => '1', 'alasan_berhenti' => 'Permintaan orang tua'])
        ->assertSessionHasNoErrors();

    $basket->refresh();
    $baru = $basket->pindahKe;

    expect($basket->status)->toBe(StatusPendaftaran::Pindah)
        ->and($baru->ekstrakurikuler_id)->toBe($this->swimming->id)
        ->and($baru->tanggal_daftar->toDateString())->toBe('2026-10-15')
        ->and(statusTagihan($basket))->toMatchArray(['2026-10' => 'dibatalkan:80000', '2026-11' => 'dibatalkan:80000'])
        ->and(statusTagihan($baru))->toBe(['2026-10' => 'lunas:0', '2026-11' => 'lunas:0'])
        ->and(PenyesuaianDana::sole())
        ->jenis_penyesuaian->toBe(JenisPenyesuaian::AlihKegiatan)
        ->nominal->toBe(160000);
});

test('pindah ke ekskul lebih mahal: kegiatan baru mulai bulan berikutnya, pembayaran di muka jadi potongan', function () {
    $basket = ikut($this->basket);
    bayar($basket, ['2026-10', '2026-11']); // Rp80.000 x 2

    ubah($basket, 'pindah', ['ekstrakurikuler_id' => $this->english->id, 'tanggal' => '2026-10-15', 'konfirmasi' => '1', 'alasan_berhenti' => 'Pindah minat'])
        ->assertSessionHasNoErrors();

    $baru = $basket->fresh()->pindahKe;

    expect($baru->tanggal_daftar->toDateString())->toBe('2026-11-01')
        ->and(statusTagihan($basket->fresh()))->toMatchArray(['2026-10' => 'lunas:0', '2026-11' => 'dibatalkan:80000'])
        ->and(statusTagihan($baru))->toBe(['2026-11' => 'belum:20000']) // English Club Rp100.000 dipotong Rp80.000
        ->and(PenyesuaianDana::where('jenis_penyesuaian', JenisPenyesuaian::RefundBerhenti)->exists())->toBeFalse();
});

test('pindah ke ekskul lebih murah: selisih yang melewati akhir semester direfund', function () {
    $english = ikut($this->english);
    bayar($english, ['2026-10', '2026-11']); // Rp100.000 x 2

    ubah($english, 'pindah', ['ekstrakurikuler_id' => $this->basket->id, 'tanggal' => '2026-10-15', 'konfirmasi' => '1', 'alasan_berhenti' => 'Pindah minat']);

    $baru = $english->fresh()->pindahKe;

    expect(statusTagihan($baru))->toBe(['2026-11' => 'lunas:0'])
        ->and(PenyesuaianDana::where('jenis_penyesuaian', JenisPenyesuaian::AlihKegiatan)->value('nominal'))->toBe(80000)
        ->and(PenyesuaianDana::where('jenis_penyesuaian', JenisPenyesuaian::RefundBerhenti)->value('nominal'))->toBe(20000); // Desember tidak ditagih
});

test('pindah ditolak bila jadwal tujuan bentrok, dan tidak ada yang berubah', function () {
    ikut($this->basket); // Sabtu 07.00–08.00
    $english = ikut($this->english);

    ubah($english, 'pindah', ['ekstrakurikuler_id' => $this->olimpiade->id, 'tanggal' => '2027-01-01'])
        ->assertSessionHasErrors('kegiatan');

    expect($english->fresh()->status)->toBe(StatusPendaftaran::Aktif)
        ->and(Pendaftaran::count())->toBe(2);
});

test('transfer yang belum diverifikasi menahan pemberhentian', function () {
    $les = ikut($this->les);
    bayar($les, ['2026-11'], MetodeTransaksi::Transfer);

    ubah($les, 'berhenti', ['tanggal' => '2026-11-01'])->assertSessionHasErrors('tanggal');

    expect($les->fresh()->status)->toBe(StatusPendaftaran::Aktif);
});

test('TU menandai refund sudah dikembalikan; Bendahara hanya melihat', function () {
    $les = ikut($this->les);
    bayar($les, ['2026-11']);
    ubah($les, 'berhenti', ['tanggal' => '2026-11-01']);
    $refund = PenyesuaianDana::sole();
    $bendahara = User::factory()->create()->assignRole('bendahara');

    $this->actingAs($bendahara)->get('/les-ekskul/refund')->assertOk();
    $this->actingAs($bendahara)->post("/les-ekskul/refund/{$refund->id}/dikembalikan")->assertForbidden();

    $this->actingAs($this->tu)->post("/les-ekskul/refund/{$refund->id}/dikembalikan")->assertSessionHasNoErrors();
    expect($refund->fresh()->status)->toBe(StatusPenyesuaian::Berhasil);

    $this->actingAs($this->tu)->post("/les-ekskul/refund/{$refund->id}/dikembalikan")->assertSessionHasErrors('refund');
});

test('siswa berkondisi khusus dibebaskan dari laporan kewajiban ekskul dan bisa dicabut', function () {
    $siswaKelas = SiswaKelas::sole();
    $belum = fn () => collect($this->actingAs($this->tu)->get('/les-ekskul/pendaftaran')->inertiaProps('belumMemenuhi'))->pluck('siswa.nama')->all();

    expect($belum())->toBe(['Budi Uji']);

    $this->actingAs($this->tu)->post("/les-ekskul/siswa-kelas/{$siswaKelas->id}/bebas-ekskul", ['alasan' => ''])->assertSessionHasErrors('alasan');
    $this->actingAs($this->tu)->post("/les-ekskul/siswa-kelas/{$siswaKelas->id}/bebas-ekskul", ['alasan' => 'Kondisi kesehatan']);
    expect($belum())->toBe([]);

    $this->actingAs($this->tu)->delete("/les-ekskul/siswa-kelas/{$siswaKelas->id}/bebas-ekskul");
    expect($belum())->toBe(['Budi Uji']);
});
