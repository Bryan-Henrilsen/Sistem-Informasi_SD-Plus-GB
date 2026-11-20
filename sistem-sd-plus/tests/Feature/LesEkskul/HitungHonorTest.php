<?php

use App\Domains\LesEkskul\Enums\JenisPengajar;
use App\Domains\LesEkskul\Enums\PeranHonor;
use App\Domains\LesEkskul\Models\HonorDetail;
use App\Domains\LesEkskul\Models\HonorFlatJenis;
use App\Domains\LesEkskul\Models\HonorKegiatan;
use App\Domains\LesEkskul\Models\PeriodeLaporan;
use App\Domains\LesEkskul\Services\HonorService;
use App\Domains\LesEkskul\Services\PengaturanHonorService;
use App\Models\Kelas;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/Pendukung/HonorUji.php';

// Angka mengacu rekap honor sekolah Agustus 2026 (docs/Referensi_Skema_Honor.xlsx, nama diganti fiktif)
beforeEach(function () {
    hhSetup($this);
});

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

test('Kepala Sekolah mengatur honor flat per periode; hitung ulang tidak menimpanya', function () {
    hhSiapkanReferensi();
    app(HonorService::class)->hitung($this->periode);
    $pramuka = HonorFlatJenis::where('kode', 'pramuka')->first();
    $soal = HonorFlatJenis::where('kode', 'pembuat_soal_les')->first();
    $wali2B = User::where('name', 'Wali 2B')->first();
    $atur = fn (User $oleh, HonorFlatJenis $jenis, array $penerima) => $this->actingAs($oleh)
        ->post("/les-ekskul/honor/rekap/{$this->periode->id}/flat/{$jenis->id}", ['penerima' => $penerima]);

    $atur($this->bendahara, $pramuka, [])->assertForbidden();
    $atur($this->kepsek, $pramuka, [])->assertSessionHasNoErrors(); // bulan ini tidak ada Pramuka
    $atur($this->kepsek, $soal, [['user_id' => $wali2B->id, 'jumlah' => 5]])->assertSessionHasNoErrors(); // 5 soal bulan ini

    $flat = fn () => HonorDetail::where('peran', PeranHonor::HonorFlat)->with('penerimaUser')->get()
        ->mapWithKeys(fn ($d) => [$d->penerimaUser->name => $d->nominal])->all();
    expect($flat())->toBe(['Wali 2B' => 200000]);

    app(HonorService::class)->hitung($this->periode); // hitung ulang honor persentase
    expect($flat())->toBe(['Wali 2B' => 200000]);

    $this->actingAs($this->kepsek)->get("/les-ekskul/honor/rekap?periode={$this->periode->id}")->assertInertia(fn (Assert $page) => $page
        ->where('bisaAturFlat', true)
        ->where('honorFlat', fn ($j) => collect($j)->firstWhere('nama', 'Pramuka')['dibayar'] === false
            && collect($j)->firstWhere('nama', 'Pembuat Soal Les')['penerima'][0]['jumlah'] === 5)
    );

    app(HonorService::class)->tandaiDiketahui($this->periode, $this->kepsek);
    $atur($this->kepsek, $pramuka, [['user_id' => $wali2B->id, 'jumlah' => 1]])->assertSessionHasErrors('honor');
});
