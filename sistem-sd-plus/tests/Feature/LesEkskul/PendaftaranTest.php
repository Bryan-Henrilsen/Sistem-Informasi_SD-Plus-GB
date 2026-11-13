<?php

use App\Domains\LesEkskul\Enums\StatusPendaftaran;
use App\Domains\LesEkskul\Models\Ekstrakurikuler;
use App\Domains\LesEkskul\Models\JadwalEkskul;
use App\Domains\LesEkskul\Models\LesTambahan;
use App\Domains\LesEkskul\Models\Pendaftaran;
use App\Domains\LesEkskul\Models\PeriodeLaporan;
use App\Domains\LesEkskul\Models\Tagihan;
use App\Models\Kelas;
use App\Models\Siswa;
use App\Models\SiswaKelas;
use App\Models\TahunAjaran;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->travelTo('2026-08-20 09:00');

    $this->tu = User::factory()->create()->assignRole('tu');

    $this->ta = TahunAjaran::create(['nama' => '2026/2027', 'tanggal_mulai' => '2026-07-13', 'tanggal_selesai' => '2027-06-30', 'status_aktif' => true]);
    $this->ta->semester()->createMany([
        ['urutan' => 1, 'nama' => 'Semester 1', 'tanggal_mulai' => '2026-07-13', 'tanggal_selesai' => '2026-12-31'],
        ['urutan' => 2, 'nama' => 'Semester 2', 'tanggal_mulai' => '2027-01-01', 'tanggal_selesai' => '2027-06-30'],
    ]);
    PeriodeLaporan::create(['bulan' => '2026-08-01', 'tanggal_mulai' => '2026-07-23', 'tanggal_selesai' => '2026-08-22']);

    $this->kelas3 = Kelas::create(['tahun_ajaran_id' => $this->ta->id, 'jenjang' => 3, 'nama_kelas' => '3A']);
    $this->kelas5 = Kelas::create(['tahun_ajaran_id' => $this->ta->id, 'jenjang' => 5, 'nama_kelas' => '5A']);
    $this->les3 = LesTambahan::create(['kelas_id' => $this->kelas3->id, 'biaya' => 150000]);
    $this->les5 = LesTambahan::create(['kelas_id' => $this->kelas5->id, 'biaya' => 180000]);

    $this->siswa = siswaDiKelas('Budi Uji', $this->kelas3);

    // Basket: jam beda per jenjang. English Club: induk berkelas paralel. Olimpiade: bentrok dengan Basket kelas 1–3
    $this->basket = ekskul('Basket', ['kuota' => 2]);
    JadwalEkskul::create(['ekstrakurikuler_id' => $this->basket->id, 'hari' => 6, 'jam_mulai' => '07:00', 'jam_selesai' => '08:00', 'jenjang_min' => 1, 'jenjang_max' => 3, 'lokasi' => 'Lapangan']);
    JadwalEkskul::create(['ekstrakurikuler_id' => $this->basket->id, 'hari' => 6, 'jam_mulai' => '08:00', 'jam_selesai' => '09:00', 'jenjang_min' => 4, 'jenjang_max' => 6, 'lokasi' => 'Lapangan']);
    $this->olimpiade = ekskul('Olimpiade MTK', ['biaya' => 0, 'honor_flat' => true]);
    JadwalEkskul::create(['ekstrakurikuler_id' => $this->olimpiade->id, 'hari' => 6, 'jam_mulai' => '07:30', 'jam_selesai' => '08:30', 'lokasi' => 'Perpustakaan']);
    $this->english = ekskul('English Club', ['kuota' => 10]);
    $this->starter = ekskul('English Club Starter', ['parent_ekstrakurikuler_id' => $this->english->id, 'kuota' => 1]);
    $this->mover = ekskul('English Club Mover', ['parent_ekstrakurikuler_id' => $this->english->id, 'kuota' => 5]);
    $this->coding = ekskul('Coding', ['jenjang_min' => 4, 'jenjang_max' => 6]);
    $this->pramuka = ekskul('Pramuka', ['biaya' => 0, 'wajib_otomatis' => true, 'jenjang_min' => 4, 'jenjang_max' => 6]);
});

function siswaDiKelas(string $nama, Kelas $kelas): Siswa
{
    $siswa = Siswa::create(['nama' => $nama]);
    SiswaKelas::create(['siswa_id' => $siswa->id, 'kelas_id' => $kelas->id]);

    return $siswa;
}

function ekskul(string $nama, array $kolom = []): Ekstrakurikuler
{
    return Ekstrakurikuler::create(['tahun_ajaran_id' => test()->ta->id, 'nama' => $nama, 'biaya' => 80000, ...$kolom]);
}

function daftarkan(array $data)
{
    return test()->actingAs(test()->tu)->post('/les-ekskul/pendaftaran', [
        'siswa_id' => test()->siswa->id,
        'tanggal_daftar' => '2026-08-20',
        ...$data,
    ]);
}

test('TU mendaftarkan siswa ke les kelasnya dan tagihan bulan berjalan langsung dibuat', function () {
    daftarkan(['jenis' => 'les_tambahan', 'kegiatan_id' => $this->les3->id])
        ->assertRedirect("/les-ekskul/pendaftaran/tambah?siswa={$this->siswa->id}");

    $pendaftaran = Pendaftaran::sole();
    expect($pendaftaran->les_tambahan_id)->toBe($this->les3->id)
        ->and(Tagihan::where('pendaftaran_id', $pendaftaran->id)->get()->map(fn (Tagihan $t) => $t->bulan_tagihan->format('Y-m'))->all())->toBe(['2026-08']);
});

test('les kelas lain, ekskul di luar jenjang, dan ekskul wajib otomatis ditolak', function () {
    daftarkan(['jenis' => 'les_tambahan', 'kegiatan_id' => $this->les5->id])->assertSessionHasErrors('kegiatan');
    daftarkan(['jenis' => 'ekstrakurikuler', 'kegiatan_id' => $this->coding->id])->assertSessionHasErrors('kegiatan');
    daftarkan(['jenis' => 'ekstrakurikuler', 'kegiatan_id' => $this->pramuka->id])->assertSessionHasErrors('kegiatan');
    daftarkan(['jenis' => 'ekstrakurikuler', 'kegiatan_id' => $this->starter->id])->assertSessionHasErrors('kegiatan'); // kelas paralel lewat assign

    expect(Pendaftaran::count())->toBe(0);
});

test('pendaftaran ganda ditolak, termasuk bila sudah di kelas paralel; setelah berhenti boleh daftar lagi', function () {
    daftarkan(['jenis' => 'ekstrakurikuler', 'kegiatan_id' => $this->english->id]);
    $pertama = Pendaftaran::sole();
    $pertama->update(['ekstrakurikuler_id' => $this->mover->id]);

    daftarkan(['jenis' => 'ekstrakurikuler', 'kegiatan_id' => $this->english->id])->assertSessionHasErrors('kegiatan');

    $pertama->update(['status' => StatusPendaftaran::Berhenti, 'tanggal_efektif_berhenti' => '2026-08-20']);
    daftarkan(['jenis' => 'ekstrakurikuler', 'kegiatan_id' => $this->english->id])->assertSessionHasNoErrors();

    expect(Pendaftaran::count())->toBe(2);
});

test('jadwal bentrok selalu ditolak, sesuai jam jenjang siswa', function () {
    daftarkan(['jenis' => 'ekstrakurikuler', 'kegiatan_id' => $this->basket->id]); // kelas 3: Sabtu 07.00–08.00

    daftarkan(['jenis' => 'ekstrakurikuler', 'kegiatan_id' => $this->olimpiade->id, 'abaikan_kuota' => true])
        ->assertSessionHasErrors(['kegiatan' => 'Jadwal bentrok dengan Basket (Sabtu 07:00–08:00).']);

    expect(Pendaftaran::count())->toBe(1);
});

test('kuota penuh hanya peringatan: perlu konfirmasi TU, lalu tersimpan', function () {
    foreach (['Andi', 'Citra'] as $nama) {
        Pendaftaran::create(['siswa_id' => siswaDiKelas($nama, $this->kelas3)->id, 'ekstrakurikuler_id' => $this->basket->id, 'tanggal_daftar' => '2026-07-20']);
    }

    daftarkan(['jenis' => 'ekstrakurikuler', 'kegiatan_id' => $this->basket->id])->assertSessionHasErrors('kuota');
    daftarkan(['jenis' => 'ekstrakurikuler', 'kegiatan_id' => $this->basket->id, 'abaikan_kuota' => true])->assertSessionHasNoErrors();

    expect(Pendaftaran::where('ekstrakurikuler_id', $this->basket->id)->count())->toBe(3);
});

test('kuota kegiatan induk dihitung gabungan dengan kelas paralelnya', function () {
    $this->english->update(['kuota' => 1]);
    Pendaftaran::create(['siswa_id' => siswaDiKelas('Andi', $this->kelas3)->id, 'ekstrakurikuler_id' => $this->mover->id, 'tanggal_daftar' => '2026-07-20']);

    daftarkan(['jenis' => 'ekstrakurikuler', 'kegiatan_id' => $this->english->id])->assertSessionHasErrors('kuota');
});

test('TU meng-assign kelas paralel; kelas lain ditolak dan kuota penuh perlu konfirmasi', function () {
    daftarkan(['jenis' => 'ekstrakurikuler', 'kegiatan_id' => $this->english->id]);
    $pendaftaran = Pendaftaran::sole();
    $assign = fn (array $data) => $this->actingAs($this->tu)->post("/les-ekskul/pendaftaran/{$pendaftaran->id}/assign", $data);

    $assign(['kelas_paralel_id' => $this->basket->id])->assertSessionHasErrors('kelas_paralel');

    Pendaftaran::create(['siswa_id' => siswaDiKelas('Andi', $this->kelas3)->id, 'ekstrakurikuler_id' => $this->starter->id, 'tanggal_daftar' => '2026-07-20']);
    $assign(['kelas_paralel_id' => $this->starter->id])->assertSessionHasErrors('kuota');
    $assign(['kelas_paralel_id' => $this->starter->id, 'abaikan_kuota' => '1'])->assertSessionHasNoErrors();

    expect($pendaftaran->fresh()->ekstrakurikuler_id)->toBe($this->starter->id);
});

test('laporan menampilkan siswa yang belum ikut ekskul, kecuali kelas 6 di semester genap', function () {
    $kelas6 = Kelas::create(['tahun_ajaran_id' => $this->ta->id, 'jenjang' => 6, 'nama_kelas' => '6A']);
    siswaDiKelas('Dewi Kelas Enam', $kelas6);
    Pendaftaran::create(['siswa_id' => $this->siswa->id, 'les_tambahan_id' => $this->les3->id, 'tanggal_daftar' => '2026-07-20']); // les saja belum cukup

    $nama = fn () => collect($this->actingAs($this->tu)->get('/les-ekskul/pendaftaran')->inertiaProps('belumMemenuhi'))->pluck('siswa.nama')->all();

    expect($nama())->toBe(['Budi Uji', 'Dewi Kelas Enam']);

    $this->travelTo('2027-01-10 09:00'); // semester genap
    expect($nama())->toBe(['Budi Uji']);
});

test('halaman tambah pendaftaran menandai kegiatan yang tidak bisa dipilih beserta alasannya', function () {
    daftarkan(['jenis' => 'ekstrakurikuler', 'kegiatan_id' => $this->basket->id]);

    $this->actingAs($this->tu)->get("/les-ekskul/pendaftaran/tambah?siswa={$this->siswa->id}")
        ->assertInertia(fn (Assert $page) => $page->component('les-ekskul/pendaftaran/create')
            ->has('terdaftar', 1)
            ->has('pilihan', 4) // les 3A, Basket, English Club, Olimpiade (Coding beda jenjang, Pramuka otomatis, kelas paralel disembunyikan)
            ->where('pilihan.1.nama', 'Basket')
            ->where('pilihan.1.error', 'Siswa sudah terdaftar aktif di kegiatan ini.')
            ->where('pilihan.3.nama', 'Olimpiade MTK')
            ->where('pilihan.3.error', 'Jadwal bentrok dengan Basket (Sabtu 07:00–08:00).'));
});

test('Bendahara dan Kepala Sekolah boleh melihat, tetapi hanya TU yang mendaftarkan', function () {
    $bendahara = User::factory()->create()->assignRole('bendahara');
    $kepsek = User::factory()->create()->assignRole('kepala_sekolah');

    $this->actingAs($kepsek)->get('/les-ekskul/pendaftaran')->assertOk();
    $this->actingAs($bendahara)->get('/les-ekskul/pendaftaran/tambah')->assertForbidden();
    $this->actingAs($bendahara)->post('/les-ekskul/pendaftaran', [])->assertForbidden();
});
