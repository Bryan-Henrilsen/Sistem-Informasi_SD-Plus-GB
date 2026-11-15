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
use App\Domains\LesEkskul\Services\HonorService;
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
use Inertia\Testing\AssertableInertia as Assert;

// Angka mengacu rekap honor sekolah Agustus 2026 (docs/Referensi_Skema_Honor.xlsx, nama diganti fiktif)
beforeEach(function () {
    $this->seed([RoleSeeder::class, HonorFlatJenisSeeder::class, SkemaHonorSeeder::class]);
    $this->travelTo('2026-09-10 09:00');

    $this->kepsek = User::factory()->create(['name' => 'Kepala Uji'])->assignRole('kepala_sekolah');
    $this->bendahara = User::factory()->create(['name' => 'Bendahara Uji'])->assignRole('bendahara');
    $this->tu = User::factory()->create(['name' => 'TU Uji'])->assignRole('tu');
    $this->it = User::factory()->create(['name' => 'IT Uji']);

    app(PengaturanHonorService::class)->simpanPenerimaPeran([
        'penanggung_jawab' => $this->kepsek->id, 'bendahara' => $this->bendahara->id, 'admin_tu' => $this->tu->id, 'admin_it' => $this->it->id,
    ], $this->kepsek);

    $this->ta = TahunAjaran::create(['nama' => '2026/2027', 'tanggal_mulai' => '2026-07-13', 'tanggal_selesai' => '2027-06-30', 'status_aktif' => true]);
    $this->periode = PeriodeLaporan::create([
        'bulan' => '2026-08-01', 'tanggal_mulai' => '2026-07-23', 'tanggal_selesai' => '2026-08-22', 'status' => 'ditutup', 'ditutup_oleh' => $this->bendahara->id, 'ditutup_at' => now(),
    ]);
    $this->laporan = LaporanHarian::create(['periode_laporan_id' => $this->periode->id, 'tanggal' => '2026-08-10']);
});

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

test('honor dihitung sama dengan rekap honor sekolah Agustus 2026', function () {
    hhSiapkanReferensi();

    expect(app(HonorService::class)->hambatan($this->periode))->toBe([]);
    app(HonorService::class)->hitung($this->periode);

    // English Club: 8.100.000 x 65% / 6 pengajar
    expect(hhNominal('English Club', PeranHonor::Pengajar))->toBe(array_fill(0, 6, 877500))
        ->and(hhNominal('English Club', PeranHonor::KasSekolah))->toBe([1620000])
        ->and(hhNominal('English Club', PeranHonor::PenanggungJawab))->toBe([567000])
        ->and(hhNominal('English Club', PeranHonor::Bendahara))->toBe([259200])
        ->and(hhNominal('English Club', PeranHonor::AdminTu))->toBe([259200])
        ->and(hhNominal('English Club', PeranHonor::AdminIt))->toBe([129600]);

    // Tari per kelas memakai skema masing-masing
    expect(hhNominal('Tari 1', PeranHonor::Pengajar))->toBe([1092000])
        ->and(hhNominal('Tari 1', PeranHonor::GuruPendamping))->toBe([])
        ->and(hhNominal('Tari 2', PeranHonor::Pengajar))->toBe([900000])
        ->and(hhNominal('Tari 2', PeranHonor::GuruPendamping))->toBe([75000]);

    // Basket: setiap pendamping 5%; kas tinggal 15%
    expect(hhNominal('Basket', PeranHonor::Pengajar))->toBe([1656000])
        ->and(hhNominal('Basket', PeranHonor::GuruPendamping))->toBe([138000, 138000])
        ->and(hhNominal('Basket', PeranHonor::KasSekolah))->toBe([414000]);

    // Swimming: dasar 3.360.000 - 1.920.000 uang kolam
    $swimming = HonorKegiatan::whereHas('ekstrakurikuler', fn ($q) => $q->where('nama', 'Swimming'))->first();
    expect($swimming->dasar_honor)->toBe(1440000)
        ->and(hhNominal('Swimming', PeranHonor::Pengajar))->toBe([468000, 468000])
        ->and(hhNominal('Swimming', PeranHonor::KasSekolah))->toBe([288000]);

    // Les kelas 2: (1.260.000 + 1.440.000 + 1.440.000) x 60% / 3 wali
    expect(hhNominal('Les 2A', PeranHonor::Pengajar))->toBe([828000])
        ->and(hhNominal('Les 2B', PeranHonor::Pengajar))->toBe([828000])
        ->and(hhNominal('Les 2C', PeranHonor::Pengajar))->toBe([828000])
        ->and(hhNominal('Les 2A', PeranHonor::Yayasan))->toBe([378000]);

    // Total semua rincian setiap kegiatan/jenjang sama dengan dasar honornya (sisa pembulatan masuk kas)
    $persen = HonorDetail::whereNotNull('honor_kegiatan_id')->sum('nominal');
    expect((int) $persen)->toBe((int) HonorKegiatan::sum('dasar_honor'));

    // Honor flat
    $flat = HonorDetail::where('peran', PeranHonor::HonorFlat)->with('penerimaUser')->get()->mapWithKeys(fn ($d) => [$d->penerimaUser->name => $d->nominal]);
    expect($flat->all())->toBe(['TU Uji' => 200000, 'Wali 2A' => 200000, 'Wali 2B' => 120000]);
});

test('rekap akhir menjumlahkan honor per orang dalam kolom rekap sekolah', function () {
    hhSiapkanReferensi();
    app(HonorService::class)->hitung($this->periode);

    $rekap = app(HonorService::class)->rekap($this->periode);
    $orang = collect($rekap['orang'])->keyBy('nama');

    expect($orang['Wali 2A']['nilai'])->toMatchArray(['les' => 828000, 'pramuka' => 200000])
        ->and($orang['Wali 2A']['jumlah'])->toBe(1028000)
        ->and($orang['Wali 2B']['nilai']['pembuat_soal_les'])->toBe(120000)
        ->and($orang['Pendamping Basket 2']['nilai']['pendamping'])->toBe(138000)
        ->and($orang['Pelatih Tari']['eksternal'])->toBeTrue()
        ->and($orang->has('Kas Sekolah'))->toBeFalse()
        // TU: admin TU dari semua kegiatan (kolom ekstra & les) + Pramuka
        ->and($orang['TU Uji']['nilai']['pramuka'])->toBe(200000)
        ->and($orang['TU Uji']['nilai']['ekstra'])->toBeGreaterThan(0)
        ->and($orang['TU Uji']['nilai']['les'])->toBeGreaterThan(0)
        ->and($rekap['total_orang'] + $rekap['kas_sekolah'] + $rekap['yayasan'])->toBe($rekap['pendapatan'] - $rekap['non_honor'] + 520000);
});

test('data belum lengkap menahan perhitungan, tutup buku tetap jalan', function () {
    $basket = hhEkskul('Basket');
    $basket->pengajar()->attach(hhPengajar('Pelatih Basket', JenisPengajar::Eksternal)->id);
    hhPendapatan($basket, 800000);

    expect(app(HonorService::class)->hambatan($this->periode))->toBe(['Basket: pengajar eksternal wajib punya guru pendamping.'])
        ->and(app(HonorService::class)->hitungBilaLengkap($this->periode))->toBeFalse()
        ->and(HonorDetail::count())->toBe(0);

    $this->actingAs($this->bendahara)->post("/les-ekskul/honor/rekap/{$this->periode->id}/hitung")->assertSessionHasErrors('honor');

    $basket->pendamping()->attach(User::factory()->create()->id);
    $this->actingAs($this->bendahara)->post("/les-ekskul/honor/rekap/{$this->periode->id}/hitung")->assertSessionHasNoErrors();
    expect(hhNominal('Basket', PeranHonor::Pengajar))->toBe([480000]);
});

test('hitung ulang mengganti hasil lama sampai Kepala Sekolah menandai Sudah Diketahui', function () {
    $ekskul = hhEkskul('Coding');
    $ekskul->pengajar()->attach(hhPengajar('Guru Coding')->id);
    hhPendapatan($ekskul, 1000000);
    $honor = app(HonorService::class);

    $honor->hitung($this->periode);
    $ekskul->pengajar()->attach(hhPengajar('Guru Coding 2')->id);
    $honor->hitung($this->periode);
    expect(hhNominal('Coding', PeranHonor::Pengajar))->toBe([325000, 325000])
        ->and(HonorKegiatan::count())->toBe(1);

    $this->actingAs($this->bendahara)->post("/les-ekskul/honor/rekap/{$this->periode->id}/diketahui")->assertForbidden();
    $this->actingAs($this->kepsek)->post("/les-ekskul/honor/rekap/{$this->periode->id}/diketahui")->assertSessionHasNoErrors();

    expect($this->periode->fresh()->honor_diketahui_oleh)->toBe($this->kepsek->id);
    $this->actingAs($this->kepsek)->post("/les-ekskul/honor/rekap/{$this->periode->id}/hitung")->assertSessionHasErrors('honor');
});

test('honor flat tidak dibayar di bulan yang tidak ditagih; periode tanpa honor tetap bisa ditandai', function () {
    $juni = PeriodeLaporan::create(['bulan' => '2027-06-01', 'tanggal_mulai' => '2027-05-23', 'tanggal_selesai' => '2027-06-22', 'status' => 'ditutup']);
    app(PengaturanHonorService::class)->simpanHonorFlat(HonorFlatJenis::where('kode', 'pramuka')->first(), 200000, [$this->tu->id => 1], $this->kepsek);
    $honor = app(HonorService::class);

    expect($honor->adaYangDihitung($juni))->toBeFalse()
        ->and($honor->adaYangDihitung($this->periode))->toBeTrue();

    $honor->hitung($juni);
    expect(HonorDetail::where('periode_laporan_id', $juni->id)->count())->toBe(0);

    $this->actingAs($this->kepsek)->post("/les-ekskul/honor/rekap/{$juni->id}/diketahui")->assertSessionHasNoErrors();
    expect($juni->fresh()->honor_diketahui_at)->not->toBeNull();
});

test('halaman rekap honor: semua role keuangan melihat, hanya Bendahara & Kepala Sekolah yang menghitung', function () {
    hhSiapkanReferensi();
    app(HonorService::class)->hitung($this->periode);

    $this->actingAs($this->tu)->get('/les-ekskul/honor/rekap')->assertInertia(fn (Assert $page) => $page
        ->component('les-ekskul/honor/rekap')
        ->where('periode.id', $this->periode->id)
        ->where('sudahDihitung', true)
        ->where('bisaHitung', false)
        ->has('rekap.kegiatan', 8)
    );
    $this->actingAs($this->tu)->post("/les-ekskul/honor/rekap/{$this->periode->id}/hitung")->assertForbidden();

    $this->actingAs($this->kepsek)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('tugas', fn ($tugas) => collect($tugas)->firstWhere('judul', 'Honor menunggu Diketahui')['jumlah'] === 1)
    );
});
