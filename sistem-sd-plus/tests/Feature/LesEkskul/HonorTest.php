<?php

use App\Domains\LesEkskul\Enums\JenisPengajar;
use App\Domains\LesEkskul\Enums\PeranHonor;
use App\Domains\LesEkskul\Enums\TipeKegiatan;
use App\Domains\LesEkskul\Models\Ekstrakurikuler;
use App\Domains\LesEkskul\Models\HonorDetail;
use App\Domains\LesEkskul\Models\HonorFlatJenis;
use App\Domains\LesEkskul\Models\HonorFlatPengaturan;
use App\Domains\LesEkskul\Models\HonorKegiatan;
use App\Domains\LesEkskul\Models\PenerimaPeranHonor;
use App\Domains\LesEkskul\Models\PengajarEkskul;
use App\Domains\LesEkskul\Models\PeriodeLaporan;
use App\Domains\LesEkskul\Models\SkemaHonor;
use App\Models\TahunAjaran;
use App\Models\User;
use Database\Seeders\HonorFlatJenisSeeder;
use Illuminate\Database\QueryException;

beforeEach(function () {
    $this->kepsek = User::factory()->create(['name' => 'Kepala Sekolah']);

    $this->tahunAjaran = TahunAjaran::create([
        'nama' => '2026/2027',
        'tanggal_mulai' => '2026-07-01',
        'tanggal_selesai' => '2027-06-30',
        'status_aktif' => true,
    ]);

    // Swimming 2026: Rp120.000 dari Rp210.000 adalah uang kolam, bukan dasar honor
    $this->swimming = Ekstrakurikuler::create([
        'tahun_ajaran_id' => $this->tahunAjaran->id,
        'nama' => 'Swimming',
        'biaya' => 210000,
        'biaya_non_honor' => 120000,
    ]);

    $this->periodeAgustus = PeriodeLaporan::create(['bulan' => '2026-08-01', 'tanggal_mulai' => '2026-07-23', 'tanggal_selesai' => '2026-08-22']);
    $this->periodeSeptember = PeriodeLaporan::create(['bulan' => '2026-09-01', 'tanggal_mulai' => '2026-08-23', 'tanggal_selesai' => '2026-09-22']);
});

// Skema ekskul internal dari Excel 2026; kolom yang berbeda cukup ditimpa lewat $kolom
function buatSkemaInternal(array $kolom = []): SkemaHonor
{
    return SkemaHonor::create([
        'tipe_kegiatan' => TipeKegiatan::Ekstrakurikuler,
        'jenis_pengajar' => JenisPengajar::Internal,
        'persen_pengajar' => 65,
        'persen_kas_sekolah' => 20,
        'persen_penanggung_jawab' => 7,
        'persen_bendahara' => 3.2,
        'persen_admin_tu' => 3.2,
        'persen_admin_it' => 1.6,
        'berlaku_sejak' => '2026-07-01',
        ...$kolom,
    ]);
}

// Honor Swimming Agustus: 16 siswa × Rp210.000, dasar honor 16 × Rp90.000
function buatHonorSwimming(PeriodeLaporan $periode, array $snapshot): HonorKegiatan
{
    return HonorKegiatan::create([
        'periode_laporan_id' => $periode->id,
        'ekstrakurikuler_id' => test()->swimming->id,
        'total_pendapatan' => 3360000,
        'dasar_honor' => 1440000,
        'snapshot_persen' => $snapshot,
    ]);
}

test('skema yang dipakai adalah yang terbaru per tanggal', function () {
    buatSkemaInternal();
    buatSkemaInternal(['persen_pengajar' => 70, 'persen_kas_sekolah' => 15, 'berlaku_sejak' => '2027-01-01']);

    expect(SkemaHonor::berlakuPada(TipeKegiatan::Ekstrakurikuler, JenisPengajar::Internal, '2026-08-22')->persen_pengajar)->toBe('65.00')
        ->and(SkemaHonor::berlakuPada(TipeKegiatan::Ekstrakurikuler, JenisPengajar::Internal, '2027-02-10')->persen_pengajar)->toBe('70.00')
        ->and(SkemaHonor::berlakuPada(TipeKegiatan::Ekstrakurikuler, JenisPengajar::Internal, '2026-06-30'))->toBeNull();
});

test('skema les ditemukan walau jenis_pengajar kosong', function () {
    SkemaHonor::create([
        'tipe_kegiatan' => TipeKegiatan::LesTambahan,
        'persen_pengajar' => 60,
        'persen_yayasan' => 30,
        'persen_kas_sekolah' => 6.85,
        'persen_penanggung_jawab' => 1,
        'persen_bendahara' => 0.75,
        'persen_admin_tu' => 0.7,
        'persen_admin_it' => 0.7,
        'berlaku_sejak' => '2026-07-01',
    ]);

    expect(SkemaHonor::berlakuPada(TipeKegiatan::LesTambahan, null, '2026-08-22')->persen_kas_sekolah)->toBe('6.85');
});

test('skema yang totalnya bukan 100 persen ditolak', function () {
    buatSkemaInternal(['persen_admin_it' => 1.5]);
})->throws(QueryException::class, 'skema_honor_total_100');

test('pemegang peran pengelola berganti sesuai berlaku_sejak', function () {
    $lama = User::factory()->create();
    $baru = User::factory()->create();

    PenerimaPeranHonor::create(['peran' => PeranHonor::AdminIt, 'user_id' => $lama->id, 'berlaku_sejak' => '2026-07-01', 'diatur_oleh' => $this->kepsek->id]);
    PenerimaPeranHonor::create(['peran' => PeranHonor::AdminIt, 'user_id' => $baru->id, 'berlaku_sejak' => '2027-01-01', 'diatur_oleh' => $this->kepsek->id]);

    expect(PenerimaPeranHonor::pemegang(PeranHonor::AdminIt, '2026-08-22')->id)->toBe($lama->id)
        ->and(PenerimaPeranHonor::pemegang(PeranHonor::AdminIt, '2027-02-10')->id)->toBe($baru->id)
        ->and(PenerimaPeranHonor::pemegang(PeranHonor::Bendahara, '2026-08-22'))->toBeNull();
});

test('honor flat tiap penerima adalah nominal per guru dikali jumlah', function () {
    $this->seed(HonorFlatJenisSeeder::class);
    $guruA = User::factory()->create();
    $guruB = User::factory()->create();

    $pengaturan = HonorFlatPengaturan::create([
        'honor_flat_jenis_id' => HonorFlatJenis::byKode('pembuat_soal_les')->id,
        'tahun_ajaran_id' => $this->tahunAjaran->id,
        'berlaku_sejak' => '2026-07-01',
        'nominal_per_guru' => 40000,
        'diatur_oleh' => $this->kepsek->id,
    ]);
    $pengaturan->penerima()->attach($guruA->id, ['jumlah' => 4]);
    $pengaturan->penerima()->attach($guruB->id);

    $honor = $pengaturan->penerima->mapWithKeys(fn (User $u) => [$u->id => $pengaturan->nominal_per_guru * $u->pivot->jumlah]);

    expect($honor[$guruA->id])->toBe(160000)
        ->and($honor[$guruB->id])->toBe(40000);
});

test('snapshot persen tidak ikut berubah saat skema baru ditambahkan', function () {
    $honor = buatHonorSwimming($this->periodeAgustus, buatSkemaInternal()->snapshot());

    buatSkemaInternal(['persen_pengajar' => 70, 'persen_kas_sekolah' => 15, 'berlaku_sejak' => '2026-08-01']);

    expect($honor->fresh()->persen(PeranHonor::Pengajar))->toBe('65.00')
        ->and($honor->persen(PeranHonor::Yayasan))->toBeNull()
        ->and($honor->tipe())->toBe(TipeKegiatan::Ekstrakurikuler);
});

test('rincian persentase selalu memakai periode dari honor_kegiatan', function () {
    $honor = buatHonorSwimming($this->periodeAgustus, buatSkemaInternal()->snapshot());

    // Periode September sengaja ditulis salah; event saving menimpanya dengan periode Agustus
    $detail = HonorDetail::create([
        'periode_laporan_id' => $this->periodeSeptember->id,
        'honor_kegiatan_id' => $honor->id,
        'peran' => PeranHonor::KasSekolah,
        'nominal' => 288000,
    ]);

    expect($detail->periode_laporan_id)->toBe($this->periodeAgustus->id)
        ->and($detail->namaPenerima())->toBe('Kas Sekolah');
});

test('nama penerima diambil dari akun atau pengajar eksternal', function () {
    $honor = buatHonorSwimming($this->periodeAgustus, buatSkemaInternal()->snapshot());
    $pelatih = PengajarEkskul::create(['nama' => 'Pelatih Renang', 'jenis' => JenisPengajar::Eksternal, 'no_hp' => '081100000000']);

    $keKepsek = HonorDetail::create(['honor_kegiatan_id' => $honor->id, 'penerima_user_id' => $this->kepsek->id, 'peran' => PeranHonor::PenanggungJawab, 'nominal' => 100800]);
    $kePelatih = HonorDetail::create(['honor_kegiatan_id' => $honor->id, 'penerima_pengajar_id' => $pelatih->id, 'peran' => PeranHonor::Pengajar, 'nominal' => 936000]);

    expect($keKepsek->namaPenerima())->toBe('Kepala Sekolah')
        ->and($kePelatih->namaPenerima())->toBe('Pelatih Renang')
        ->and($honor->detail->sum('nominal'))->toBe(1036800);
});

test('baris honor flat tanpa penerima ditolak', function () {
    $this->seed(HonorFlatJenisSeeder::class);
    $pengaturan = HonorFlatPengaturan::create([
        'honor_flat_jenis_id' => HonorFlatJenis::byKode('pramuka')->id,
        'tahun_ajaran_id' => $this->tahunAjaran->id,
        'berlaku_sejak' => '2026-07-01',
        'nominal_per_guru' => 200000,
        'diatur_oleh' => $this->kepsek->id,
    ]);

    HonorDetail::create([
        'periode_laporan_id' => $this->periodeAgustus->id,
        'honor_flat_pengaturan_id' => $pengaturan->id,
        'peran' => PeranHonor::HonorFlat,
        'nominal' => 200000,
    ]);
})->throws(QueryException::class, 'honor_detail_flat_wajib_penerima');

test('menghapus honor_kegiatan ikut menghapus rinciannya', function () {
    $honor = buatHonorSwimming($this->periodeAgustus, buatSkemaInternal()->snapshot());
    HonorDetail::create(['honor_kegiatan_id' => $honor->id, 'peran' => PeranHonor::KasSekolah, 'nominal' => 288000]);

    $honor->delete();

    expect(HonorDetail::count())->toBe(0);
});
