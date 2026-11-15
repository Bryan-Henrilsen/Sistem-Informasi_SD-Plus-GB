<?php

use App\Domains\LesEkskul\Models\Ekstrakurikuler;
use App\Domains\LesEkskul\Models\JadwalEkskul;
use App\Domains\LesEkskul\Models\LesTambahan;
use App\Domains\LesEkskul\Models\Pendaftaran;
use App\Domains\LesEkskul\Models\PeriodeLaporan;
use App\Domains\LesEkskul\Models\PeriodePendaftaran;
use App\Models\Kelas;
use App\Models\Siswa;
use App\Models\SiswaKelas;
use App\Models\TahunAjaran;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Inertia\Testing\AssertableInertia as Assert;

// Pendaftaran oleh orang tua (FR-2–FR-5, FR-24, FR-26; Ringkasan v1.11 #37)
beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->travelTo('2026-07-16 09:00');

    $this->tu = User::factory()->create()->assignRole('tu');
    $this->ortu = User::factory()->create(['email' => null, 'no_hp' => '081234567890'])->assignRole('orang_tua');

    $this->ta = TahunAjaran::create(['nama' => '2026/2027', 'tanggal_mulai' => '2026-07-13', 'tanggal_selesai' => '2027-06-30', 'status_aktif' => true]);
    // Juli tidak ditagih; tagihan pertama untuk pendaftar Juli adalah Agustus (periode laporan terbaru)
    PeriodeLaporan::create(['bulan' => '2026-07-01', 'tanggal_mulai' => '2026-07-13', 'tanggal_selesai' => '2026-07-22']);
    PeriodeLaporan::create(['bulan' => '2026-08-01', 'tanggal_mulai' => '2026-07-23', 'tanggal_selesai' => '2026-08-22']);
    $this->periode = PeriodePendaftaran::create(['tahun_ajaran_id' => $this->ta->id, 'tanggal_mulai' => '2026-07-15', 'tanggal_selesai' => '2026-07-22']);

    $kelas = Kelas::create(['tahun_ajaran_id' => $this->ta->id, 'jenjang' => 5, 'nama_kelas' => '5A']);
    $this->les = LesTambahan::create(['kelas_id' => $kelas->id, 'biaya' => 180000]);
    $this->anak = Siswa::create(['nama' => 'Anak Fiktif']);
    SiswaKelas::create(['siswa_id' => $this->anak->id, 'kelas_id' => $kelas->id]);
    $this->ortu->anak()->attach($this->anak->id);

    $this->basket = poEkskul('Basket', ['kuota' => 1]);
    JadwalEkskul::create(['ekstrakurikuler_id' => $this->basket->id, 'hari' => 6, 'jam_mulai' => '08:00', 'jam_selesai' => '09:00', 'lokasi' => 'Lapangan']);
    $this->futsal = poEkskul('Futsal');
    JadwalEkskul::create(['ekstrakurikuler_id' => $this->futsal->id, 'hari' => 6, 'jam_mulai' => '08:30', 'jam_selesai' => '09:30', 'lokasi' => 'Lapangan']);
    $this->english = poEkskul('English Club');
    $this->starter = poEkskul('English Club Starter', ['parent_ekstrakurikuler_id' => $this->english->id]);
    $this->pramuka = poEkskul('Pramuka', ['biaya' => 0, 'wajib_otomatis' => true, 'jenjang_min' => 4, 'jenjang_max' => 6]);
});

function poEkskul(string $nama, array $kolom = []): Ekstrakurikuler
{
    return Ekstrakurikuler::create(['tahun_ajaran_id' => test()->ta->id, 'nama' => $nama, 'biaya' => 80000, ...$kolom]);
}

function poDaftar(LesTambahan|Ekstrakurikuler $kegiatan, ?User $oleh = null, ?Siswa $anak = null)
{
    return test()->actingAs($oleh ?? test()->ortu)->post('/ortu/anak/'.($anak ?? test()->anak)->id.'/kegiatan', [
        'jenis' => $kegiatan instanceof LesTambahan ? 'les_tambahan' : 'ekstrakurikuler',
        'kegiatan_id' => $kegiatan->id,
    ]);
}

test('halaman kegiatan anak: kegiatan wajib, pilihan hanya induk, dan periode yang dibuka', function () {
    $this->actingAs($this->ortu)->get("/ortu/anak/{$this->anak->id}/kegiatan")->assertInertia(fn (Assert $page) => $page
        ->component('les-ekskul/orang-tua/kegiatan')
        ->where('wajib.0.nama', 'Pramuka')
        ->where('periode.tanggal_selesai', '2026-07-22')
        ->where('pilihan', fn ($pilihan) => collect($pilihan)->pluck('nama')->sort()->values()->all() === ['Basket', 'English Club', 'Futsal', 'Les Tambahan 5A'])
    );
});

test('orang tua mendaftarkan anaknya; tagihan dibuat dan kegiatan pindah ke daftar yang diikuti', function () {
    poDaftar($this->les)->assertSessionHasNoErrors();
    poDaftar($this->english)->assertSessionHasNoErrors();

    $pendaftaran = Pendaftaran::where('siswa_id', $this->anak->id)->get();
    expect($pendaftaran)->toHaveCount(2)
        ->and($pendaftaran->first()->tanggal_daftar->toDateString())->toBe('2026-07-16')
        ->and($pendaftaran->first()->tagihan()->pluck('bulan_tagihan')->map->toDateString()->all())->toBe(['2026-08-01']);

    $this->actingAs($this->ortu)->get("/ortu/anak/{$this->anak->id}/kegiatan")->assertInertia(fn (Assert $page) => $page
        ->has('diikuti', 2)
        ->where('pilihan', fn ($pilihan) => collect($pilihan)->pluck('nama')->sort()->values()->all() === ['Basket', 'Futsal'])
    );

    // Setelah TU meng-assign kelas paralel, English Club tetap dianggap sudah diikuti
    Pendaftaran::where('ekstrakurikuler_id', $this->english->id)->update(['ekstrakurikuler_id' => $this->starter->id]);
    poDaftar($this->english)->assertSessionHasErrors('kegiatan');
});

test('kuota penuh ditolak untuk orang tua, padahal TU boleh dengan konfirmasi; bentrok jadwal ditolak', function () {
    $anakLain = Siswa::create(['nama' => 'Anak Lain']);
    SiswaKelas::create(['siswa_id' => $anakLain->id, 'kelas_id' => $this->les->kelas_id]);
    $this->ortu->anak()->attach($anakLain->id);

    poDaftar($this->basket)->assertSessionHasNoErrors();
    poDaftar($this->basket, anak: $anakLain)->assertSessionHasErrors(['kegiatan' => 'Kuota Basket sudah penuh. Hubungi TU bila tetap ingin ikut.']);

    poDaftar($this->futsal)->assertSessionHasErrors('kegiatan'); // Sabtu 08:30 beririsan dengan Basket 08:00–09:00
    poDaftar($this->pramuka)->assertSessionHasErrors('kegiatan'); // wajib otomatis tidak didaftarkan
});

test('di luar periode pendaftaran orang tua tidak bisa mendaftar; anak orang lain tidak bisa dibuka', function () {
    $this->travelTo('2026-07-25 09:00');
    poDaftar($this->les)->assertSessionHasErrors(['kegiatan' => 'Pendaftaran sedang ditutup. Hubungi TU untuk pendaftaran di luar periode.']);
    $this->actingAs($this->ortu)->get("/ortu/anak/{$this->anak->id}/kegiatan")->assertInertia(fn (Assert $page) => $page
        ->where('periode', null)
        ->where('pilihan', [])
    );

    $this->travelTo('2026-07-16 09:00');
    $orangLain = User::factory()->create(['email' => null, 'no_hp' => '081299990000'])->assignRole('orang_tua');
    $this->actingAs($orangLain)->get("/ortu/anak/{$this->anak->id}/kegiatan")->assertNotFound();
    poDaftar($this->les, $orangLain)->assertNotFound();
});

test('TU mengatur periode pendaftaran: di dalam tahun ajaran dan tidak beririsan', function () {
    $simpan = fn (string $mulai, string $selesai) => $this->actingAs($this->tu)->post('/les-ekskul/periode-pendaftaran', ['tanggal_mulai' => $mulai, 'tanggal_selesai' => $selesai]);

    $simpan('2026-07-20', '2026-07-30')->assertSessionHasErrors('tanggal_mulai'); // beririsan
    $simpan('2026-06-01', '2026-06-10')->assertSessionHasErrors('tanggal_mulai'); // sebelum tahun ajaran
    $simpan('2027-01-10', '2027-01-05')->assertSessionHasErrors('tanggal_mulai'); // terbalik
    $simpan('2027-01-04', '2027-01-09')->assertSessionHasNoErrors();

    $this->actingAs($this->tu)->get('/les-ekskul/pendaftaran')->assertInertia(fn (Assert $page) => $page
        ->has('periodePendaftaran', 2)
        ->where('periodePendaftaran.0.status', 'buka')
        ->where('periodePendaftaran.1.status', 'akan_datang')
    );

    $this->actingAs($this->tu)->delete("/les-ekskul/periode-pendaftaran/{$this->periode->id}")->assertSessionHasNoErrors();
    expect(PeriodePendaftaran::count())->toBe(1);
    $this->actingAs($this->ortu)->post('/les-ekskul/periode-pendaftaran', ['tanggal_mulai' => '2027-02-01', 'tanggal_selesai' => '2027-02-05'])->assertForbidden();
});

test('beranda orang tua memberi tahu periode pendaftaran yang sedang dibuka', function () {
    $this->actingAs($this->ortu)->get('/ortu')->assertInertia(fn (Assert $page) => $page->where('periodeBuka.tanggal_selesai', '2026-07-22'));

    $this->travelTo('2026-08-01 09:00');
    $this->actingAs($this->ortu)->get('/ortu')->assertInertia(fn (Assert $page) => $page->where('periodeBuka', null));
});
