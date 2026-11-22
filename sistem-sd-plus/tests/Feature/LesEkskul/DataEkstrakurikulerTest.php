<?php

use App\Domains\LesEkskul\Enums\JenisPengajar;
use App\Domains\LesEkskul\Models\Ekstrakurikuler;
use App\Domains\LesEkskul\Models\Pendaftaran;
use App\Domains\LesEkskul\Models\PengajarEkskul;
use App\Domains\LesEkskul\Models\Tagihan;
use App\Domains\LesEkskul\Services\TagihanService;
use App\Models\Kelas;
use App\Models\Siswa;
use App\Models\SiswaKelas;
use App\Models\TahunAjaran;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Inertia\Testing\AssertableInertia as Assert;

// Data master Kegiatan Ekstrakurikuler: kegiatan, jadwal, kelas paralel, salin dari tahun lalu (Ringkasan v1.11 #42)
beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->travelTo('2026-08-10 09:00');

    $this->tu = User::factory()->create()->assignRole('tu');
    $this->ta = TahunAjaran::create(['nama' => '2026/2027', 'tanggal_mulai' => '2026-07-13', 'tanggal_selesai' => '2027-06-30', 'status_aktif' => true]);

    $this->basket = [
        'jenis' => 'biasa', 'nama' => 'Basket', 'biaya' => 80000, 'biaya_non_honor' => 0, 'kuota' => 40,
        'jenjang_min' => null, 'jenjang_max' => null,
        'jadwal' => [
            ['hari' => 6, 'jam_mulai' => '07:00', 'jam_selesai' => '08:00', 'jenjang_min' => 1, 'jenjang_max' => 3, 'lokasi' => 'Lapangan'],
            ['hari' => 6, 'jam_mulai' => '08:00', 'jam_selesai' => '09:00', 'jenjang_min' => 4, 'jenjang_max' => 6, 'lokasi' => 'Lapangan'],
        ],
        'kelas_paralel' => [],
    ];
    $this->tambah = fn (array $data, ?User $oleh = null) => $this->actingAs($oleh ?? $this->tu)->post("/les-ekskul/tahun-ajaran/{$this->ta->id}/ekstrakurikuler", $data);
    $this->ubah = fn (Ekstrakurikuler $e, array $data) => $this->actingAs($this->tu)->put("/les-ekskul/ekstrakurikuler/{$e->id}", $data);
    $this->daftarkan = function (Ekstrakurikuler $e) {
        $kelas = Kelas::firstOrCreate(['tahun_ajaran_id' => $this->ta->id, 'jenjang' => 2, 'nama_kelas' => '2A']);
        $siswa = Siswa::create(['nama' => 'Siswa '.Siswa::count()]);
        SiswaKelas::create(['siswa_id' => $siswa->id, 'kelas_id' => $kelas->id]);

        return Pendaftaran::create(['siswa_id' => $siswa->id, 'ekstrakurikuler_id' => $e->id, 'tanggal_daftar' => '2026-08-01']);
    };
});

test('TU menambah kegiatan dengan jadwal per jenjang; Kepala Sekolah dan Bendahara hanya melihat', function () {
    ($this->tambah)($this->basket)->assertSessionHasNoErrors();

    $basket = Ekstrakurikuler::sole();
    expect($basket->biaya)->toBe(80000)
        ->and($basket->jadwal)->toHaveCount(2)
        ->and($basket->jadwal->first()->jenjang_min)->toBe(1);

    foreach (['kepala_sekolah', 'bendahara'] as $role) {
        $lihat = User::factory()->create()->assignRole($role);
        $this->actingAs($lihat)->get('/les-ekskul/ekstrakurikuler')
            ->assertInertia(fn (Assert $page) => $page->component('les-ekskul/ekstrakurikuler/index')
                ->where('bisaUbah', false)
                ->where('kegiatan.0.nama', 'Basket')
                ->where('kegiatan.0.jadwal.1.jam_mulai', '08:00'));
        ($this->tambah)([...$this->basket, 'nama' => 'Futsal'], $lihat)->assertForbidden();
    }
});

test('jenis menentukan isian: honor flat & wajib otomatis Rp0, wajib tanpa kuota dan wajib berjenjang', function () {
    ($this->tambah)(['jenis' => 'honor_flat', 'nama' => 'Olimpiade MTK', 'biaya' => 50000, 'kuota' => 20, 'jenjang_min' => 4, 'jenjang_max' => 6])->assertSessionHasNoErrors();
    ($this->tambah)(['jenis' => 'wajib', 'nama' => 'Pramuka', 'kuota' => 99])->assertSessionHasErrors('jenjang_min');
    ($this->tambah)(['jenis' => 'wajib', 'nama' => 'Pramuka', 'kuota' => 99, 'jenjang_min' => 4, 'jenjang_max' => 6])->assertSessionHasNoErrors();
    ($this->tambah)(['jenis' => 'biasa', 'nama' => 'Gratis', 'biaya' => 0])->assertSessionHasErrors('biaya');

    $olimpiade = Ekstrakurikuler::where('nama', 'Olimpiade MTK')->sole();
    $pramuka = Ekstrakurikuler::where('nama', 'Pramuka')->sole();
    expect($olimpiade->biaya)->toBe(0)->and($olimpiade->honor_flat)->toBeTrue()->and($olimpiade->kuota)->toBe(20)
        ->and($pramuka->biaya)->toBe(0)->and($pramuka->wajib_otomatis)->toBeTrue()->and($pramuka->kuota)->toBeNull();
});

test('validasi: nama ganda, non-honor melebihi biaya, jam terbalik, jenjang jadwal di luar jenjang kegiatan', function () {
    ($this->tambah)($this->basket);

    ($this->tambah)([...$this->basket, 'nama' => 'basket'])->assertSessionHasErrors('nama');
    ($this->tambah)([...$this->basket, 'nama' => 'Swimming', 'biaya_non_honor' => 90000])->assertSessionHasErrors('biaya_non_honor');
    ($this->tambah)([...$this->basket, 'nama' => 'Coding', 'jadwal' => [['hari' => 5, 'jam_mulai' => '16:00', 'jam_selesai' => '15:00', 'lokasi' => 'Lab']]])
        ->assertSessionHasErrors('jadwal.0.jam_selesai');
    ($this->tambah)([...$this->basket, 'nama' => 'Coding', 'jenjang_min' => 4, 'jenjang_max' => 6])->assertSessionHasErrors('jadwal.0.jenjang_min'); // jadwal kelas 1–3
    ($this->tambah)([...$this->basket, 'nama' => 'Coding', 'jadwal' => [['hari' => 3, 'jam_mulai' => '15:00', 'jam_selesai' => '16:00', 'lokasi' => 'Lab']]])
        ->assertSessionHasErrors('jadwal.0.hari'); // hanya Jumat & Sabtu

    expect(Ekstrakurikuler::count())->toBe(1);
});

test('kelas paralel mengikuti biaya induk; yang sudah punya pendaftar tidak bisa dihapus', function () {
    ($this->tambah)([...$this->basket, 'nama' => 'English Club', 'biaya' => 100000, 'jadwal' => [], 'kelas_paralel' => [
        ['nama' => 'English Club Starter', 'kuota' => 15],
        ['nama' => 'English Club Flyer', 'kuota' => 15],
    ]])->assertSessionHasNoErrors();
    $induk = Ekstrakurikuler::where('nama', 'English Club')->sole();
    [$starter, $flyer] = [$induk->kelasParalel()->where('nama', 'English Club Starter')->sole(), $induk->kelasParalel()->where('nama', 'English Club Flyer')->sole()];
    ($this->daftarkan)($starter);

    // Biaya induk naik → kelas paralel ikut; Flyer (tanpa pendaftar) dihapus, kelas baru ditambah
    $data = [...$this->basket, 'nama' => 'English Club', 'biaya' => 120000, 'jadwal' => [], 'kelas_paralel' => [
        ['id' => $starter->id, 'nama' => 'English Club Starter', 'kuota' => 20],
        ['nama' => 'English Club Mover', 'kuota' => 15],
    ]];
    ($this->ubah)($induk, $data)->assertSessionHasNoErrors();

    expect($starter->fresh()->biaya)->toBe(120000)
        ->and($starter->fresh()->kuota)->toBe(20)
        ->and(Ekstrakurikuler::find($flyer->id))->toBeNull()
        ->and($induk->kelasParalel()->pluck('nama')->sort()->values()->all())->toBe(['English Club Mover', 'English Club Starter']);

    // Starter punya pendaftar → tidak bisa dibuang dari form
    ($this->ubah)($induk, [...$data, 'kelas_paralel' => [['nama' => 'English Club Mover', 'kuota' => 15]]])->assertSessionHasErrors('kelas_paralel');
    expect($starter->fresh())->not->toBeNull();
});

test('perubahan biaya hanya berlaku untuk tagihan yang dibuat sesudahnya; jenis terkunci bila ada pendaftar', function () {
    ($this->tambah)($this->basket);
    $basket = Ekstrakurikuler::sole();
    $pendaftaran = ($this->daftarkan)($basket);
    $agustus = app(TagihanService::class)->buatUntukBulan($pendaftaran, '2026-08-01');

    ($this->ubah)($basket, [...$this->basket, 'biaya' => 90000])->assertSessionHasNoErrors();
    $september = app(TagihanService::class)->buatUntukBulan($pendaftaran->fresh(), '2026-09-01');

    expect($agustus->fresh()->nominal)->toBe(80000)->and($september->nominal)->toBe(90000);

    ($this->ubah)($basket, [...$this->basket, 'jenis' => 'honor_flat'])->assertSessionHasErrors('jenis');
});

test('hapus hanya bila belum pernah punya pendaftar', function () {
    ($this->tambah)($this->basket);
    ($this->tambah)([...$this->basket, 'nama' => 'Futsal']);
    [$basket, $futsal] = [Ekstrakurikuler::where('nama', 'Basket')->sole(), Ekstrakurikuler::where('nama', 'Futsal')->sole()];
    ($this->daftarkan)($basket)->update(['status' => 'berhenti', 'tanggal_efektif_berhenti' => '2026-08-31']); // berhenti tetap dihitung

    $this->actingAs($this->tu)->delete("/les-ekskul/ekstrakurikuler/{$basket->id}")->assertSessionHasErrors('kegiatan');
    $this->actingAs($this->tu)->delete("/les-ekskul/ekstrakurikuler/{$futsal->id}")->assertSessionHasNoErrors();

    expect(Ekstrakurikuler::pluck('nama')->all())->toBe(['Basket'])
        ->and(Tagihan::count())->toBe(0);
});

test('salin dari tahun ajaran lalu: kegiatan, jadwal, kelas paralel, pengajar, pendamping; hanya bila tahun tujuan kosong', function () {
    ($this->tambah)([...$this->basket, 'kelas_paralel' => [['nama' => 'Basket Putri', 'kuota' => 20]]]);
    $basket = Ekstrakurikuler::where('nama', 'Basket')->sole();
    $basket->update(['kategori_pendamping' => 'olahraga', 'honor_per_kelas_paralel' => true]);
    $pelatih = PengajarEkskul::create(['nama' => 'Pelatih Fiktif', 'jenis' => JenisPengajar::Eksternal, 'no_hp' => '081200000000', 'status_aktif' => true]);
    $basket->pengajar()->attach($pelatih->id);
    $basket->pendamping()->attach($this->tu->id);
    ($this->daftarkan)($basket);

    $baru = TahunAjaran::create(['nama' => '2027/2028', 'tanggal_mulai' => '2027-07-12', 'tanggal_selesai' => '2028-06-30', 'status_aktif' => false]);
    $this->actingAs($this->tu)->get("/les-ekskul/ekstrakurikuler?tahun={$baru->id}")
        ->assertInertia(fn (Assert $page) => $page->where('sumberSalinan.nama', '2026/2027')->has('kegiatan', 0));

    $this->actingAs($this->tu)->post("/les-ekskul/tahun-ajaran/{$baru->id}/ekstrakurikuler/salin")->assertSessionHasNoErrors();

    $salinan = Ekstrakurikuler::where('tahun_ajaran_id', $baru->id)->kegiatanInduk()->with('jadwal', 'kelasParalel', 'pengajar', 'pendamping')->sole();
    expect($salinan->nama)->toBe('Basket')
        ->and($salinan->jadwal)->toHaveCount(2)
        ->and($salinan->kelasParalel->pluck('nama')->all())->toBe(['Basket Putri'])
        ->and($salinan->pengajar->pluck('id')->all())->toBe([$pelatih->id])
        ->and($salinan->pendamping->pluck('id')->all())->toBe([$this->tu->id])
        ->and($salinan->kategori_pendamping->value)->toBe('olahraga')
        ->and($salinan->honor_per_kelas_paralel)->toBeTrue()
        ->and(Pendaftaran::whereIn('ekstrakurikuler_id', [$salinan->id])->count())->toBe(0); // pendaftaran tidak ikut

    // Tahun tujuan sudah berisi → tidak bisa menyalin lagi
    $this->actingAs($this->tu)->post("/les-ekskul/tahun-ajaran/{$baru->id}/ekstrakurikuler/salin")->assertSessionHasErrors('salin');
    expect(Ekstrakurikuler::where('tahun_ajaran_id', $baru->id)->count())->toBe(2);
});
