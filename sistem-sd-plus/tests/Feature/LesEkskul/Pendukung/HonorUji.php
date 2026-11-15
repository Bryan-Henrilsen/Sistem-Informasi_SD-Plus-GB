<?php

use App\Domains\LesEkskul\Enums\JenisPengajar;
use App\Domains\LesEkskul\Enums\PeranHonor;
use App\Domains\LesEkskul\Models\Ekstrakurikuler;
use App\Domains\LesEkskul\Models\HonorDetail;
use App\Domains\LesEkskul\Models\HonorFlatJenis;
use App\Domains\LesEkskul\Models\HonorKegiatan;
use App\Domains\LesEkskul\Models\LaporanHarian;
use App\Domains\LesEkskul\Models\LesTambahan;
use App\Domains\LesEkskul\Models\Pendaftaran;
use App\Domains\LesEkskul\Models\PengajarEkskul;
use App\Domains\LesEkskul\Models\PeriodeLaporan;
use App\Domains\LesEkskul\Models\Tagihan;
use App\Domains\LesEkskul\Models\Transaksi;
use App\Domains\LesEkskul\Services\PengaturanHonorService;
use App\Models\Guru;
use App\Models\Kelas;
use App\Models\Siswa;
use App\Models\TahunAjaran;
use App\Models\User;
use Database\Seeders\HonorFlatJenisSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SkemaHonorSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// Data uji honor bersama (HitungHonorTest, LaporanKasTest); angka mengacu rekap honor sekolah Agustus 2026
// (docs/Referensi_Skema_Honor.xlsx, nama diganti fiktif). Dipanggil dari beforeEach: hhSetup($this)
function hhSetup(TestCase $test): void
{
    $test->seed([RoleSeeder::class, HonorFlatJenisSeeder::class, SkemaHonorSeeder::class]);
    $test->travelTo('2026-09-10 09:00');

    $test->kepsek = User::factory()->create(['name' => 'Kepala Uji'])->assignRole('kepala_sekolah');
    $test->bendahara = User::factory()->create(['name' => 'Bendahara Uji'])->assignRole('bendahara');
    $test->tu = User::factory()->create(['name' => 'TU Uji'])->assignRole('tu');
    $test->it = User::factory()->create(['name' => 'IT Uji']);

    app(PengaturanHonorService::class)->simpanPenerimaPeran([
        'penanggung_jawab' => $test->kepsek->id, 'bendahara' => $test->bendahara->id, 'admin_tu' => $test->tu->id, 'admin_it' => $test->it->id,
    ], $test->kepsek);

    $test->ta = TahunAjaran::create(['nama' => '2026/2027', 'tanggal_mulai' => '2026-07-13', 'tanggal_selesai' => '2027-06-30', 'status_aktif' => true]);
    $test->periode = PeriodeLaporan::create([
        'bulan' => '2026-08-01', 'tanggal_mulai' => '2026-07-23', 'tanggal_selesai' => '2026-08-22', 'status' => 'ditutup', 'ditutup_oleh' => $test->bendahara->id, 'ditutup_at' => now(),
    ]);
    $test->laporan = LaporanHarian::create(['periode_laporan_id' => $test->periode->id, 'tanggal' => '2026-08-10']);
}

function hhEkskul(string $nama, array $kolom = []): Ekstrakurikuler
{
    return Ekstrakurikuler::create(['tahun_ajaran_id' => test()->ta->id, 'nama' => $nama, 'biaya' => 100000, ...$kolom]);
}

function hhPengajar(string $nama, JenisPengajar $jenis = JenisPengajar::Internal): PengajarEkskul
{
    $user = $jenis === JenisPengajar::Internal ? User::factory()->create(['name' => $nama]) : null;

    return PengajarEkskul::create(['nama' => $nama, 'jenis' => $jenis, 'user_id' => $user?->id, 'no_hp' => '0812']);
}

function hhGuru(string $nama): User
{
    $user = User::factory()->create(['name' => $nama]);
    Guru::create(['user_id' => $user->id, 'kode_guru' => strtoupper(substr($nama, 0, 3)).$user->id]);

    return $user;
}

// Pendapatan satu kegiatan di periode ini: satu tagihan dibayar cash lalu diakui saat tutup buku
function hhPendapatan(Ekstrakurikuler|LesTambahan $kegiatan, int $total): void
{
    $kolom = $kegiatan instanceof LesTambahan ? ['les_tambahan_id' => $kegiatan->id] : ['ekstrakurikuler_id' => $kegiatan->id];
    $siswa = Siswa::create(['nama' => 'Siswa '.$kegiatan->id.'-'.$total]);
    $pendaftaran = Pendaftaran::create(['siswa_id' => $siswa->id, 'tanggal_daftar' => '2026-07-20', ...$kolom]);
    $tagihan = Tagihan::create(['pendaftaran_id' => $pendaftaran->id, 'bulan_tagihan' => '2026-08-01', 'nominal' => $total]);

    $transaksi = Transaksi::create([
        'siswa_id' => $siswa->id, 'laporan_harian_id' => test()->laporan->id, 'tanggal' => '2026-08-10', 'metode' => 'cash', 'nominal' => $total,
        'status' => 'terverifikasi', 'diinput_oleh' => test()->tu->id, 'diverifikasi_oleh' => test()->tu->id, 'diverifikasi_at' => now(),
    ]);
    $transaksi->tagihan()->attach($tagihan->id, ['nominal' => $total]);

    DB::table('alokasi_transaksi')->where('transaksi_id', $transaksi->id)->update(['diakui_periode_id' => test()->periode->id]);
}

// Nominal satu peran di honor kegiatan bernama $nama; $penerima untuk memilih satu orang
function hhNominal(string $nama, PeranHonor $peran, ?string $penerima = null): array
{
    $kegiatan = HonorKegiatan::with(['ekstrakurikuler', 'lesTambahan.kelas'])->get()
        ->first(fn (HonorKegiatan $k) => ($k->ekstrakurikuler?->nama ?? 'Les '.$k->lesTambahan->kelas->nama_kelas) === $nama);

    return $kegiatan->detail()->with(['penerimaUser', 'penerimaPengajar'])->where('peran', $peran)->get()
        ->filter(fn (HonorDetail $d) => $penerima === null || $d->namaPenerima() === $penerima)
        ->pluck('nominal')->all();
}

function hhSiapkanReferensi(): void
{
    // English Club digabung: 3 kelas paralel, 6 pengajar internal
    $ec = hhEkskul('English Club');
    $kelasEc = collect([1, 2, 3])->map(fn ($i) => hhEkskul("English Club {$i}", ['parent_ekstrakurikuler_id' => $ec->id]));
    foreach ($kelasEc as $i => $kelas) {
        $kelas->pengajar()->attach([hhPengajar('EC Guru '.($i * 2 + 1))->id, hhPengajar('EC Guru '.($i * 2 + 2))->id]);
    }
    hhPendapatan($kelasEc[0], 4000000);
    hhPendapatan($kelasEc[1], 4100000);

    // Tari per kelas: Tari 1 pengajar internal, Tari 2 pelatih eksternal dengan pendamping
    $tari = hhEkskul('Tari', ['honor_per_kelas_paralel' => true]);
    $tari1 = hhEkskul('Tari 1', ['parent_ekstrakurikuler_id' => $tari->id]);
    $tari2 = hhEkskul('Tari 2', ['parent_ekstrakurikuler_id' => $tari->id]);
    $tari1->pengajar()->attach(hhPengajar('Anci Tari')->id);
    $tari2->pengajar()->attach(hhPengajar('Pelatih Tari', JenisPengajar::Eksternal)->id);
    $tari2->pendamping()->attach(User::factory()->create(['name' => 'Pendamping Tari'])->id);
    hhPendapatan($tari1, 1680000);
    hhPendapatan($tari2, 1500000);

    // Basket eksternal dengan dua pendamping: pendamping kedua dibayar dari kas
    $basket = hhEkskul('Basket');
    $basket->pengajar()->attach(hhPengajar('Pelatih Basket', JenisPengajar::Eksternal)->id);
    $basket->pendamping()->attach([User::factory()->create(['name' => 'Pendamping Basket 1'])->id, User::factory()->create(['name' => 'Pendamping Basket 2'])->id]);
    hhPendapatan($basket, 2760000);

    // Swimming: Rp80.000 dari Rp140.000 per siswa adalah uang kolam
    $swimming = hhEkskul('Swimming', ['biaya' => 140000, 'biaya_non_honor' => 80000]);
    $swimming->pengajar()->attach([hhPengajar('Swim Guru 1')->id, hhPengajar('Swim Guru 2')->id]);
    hhPendapatan($swimming, 3360000);

    // Les kelas 2: porsi pengajar ketiga kelas dijumlah lalu dibagi rata ke wali kelas
    foreach (['2A' => 1260000, '2B' => 1440000, '2C' => 1440000] as $namaKelas => $total) {
        $kelas = Kelas::create(['tahun_ajaran_id' => test()->ta->id, 'jenjang' => 2, 'nama_kelas' => $namaKelas]);
        $kelas->update(['wali_kelas_id' => Guru::where('user_id', hhGuru("Wali {$namaKelas}")->id)->value('id')]);
        hhPendapatan(LesTambahan::create(['kelas_id' => $kelas->id, 'biaya' => 180000]), $total);
    }

    // Honor flat: Pramuka Rp200.000 per orang, Pembuat Soal Les Rp40.000 x jumlah soal
    $layanan = app(PengaturanHonorService::class);
    $layanan->simpanHonorFlat(HonorFlatJenis::where('kode', 'pramuka')->first(), 200000, [test()->tu->id => 1, User::where('name', 'Wali 2A')->value('id') => 1], test()->kepsek);
    $layanan->simpanHonorFlat(HonorFlatJenis::where('kode', 'pembuat_soal_les')->first(), 40000, [User::where('name', 'Wali 2B')->value('id') => 3], test()->kepsek);
}
