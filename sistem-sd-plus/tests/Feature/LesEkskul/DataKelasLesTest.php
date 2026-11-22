<?php

use App\Domains\LesEkskul\Models\LesTambahan;
use App\Domains\LesEkskul\Models\Pendaftaran;
use App\Domains\LesEkskul\Services\TagihanService;
use App\Models\Guru;
use App\Models\Kelas;
use App\Models\Siswa;
use App\Models\SiswaKelas;
use App\Models\TahunAjaran;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Inertia\Testing\AssertableInertia as Assert;

// Data master Kelas & Les Tambahan (Ringkasan v1.11 #43)
beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->travelTo('2026-08-10 09:00');

    $this->tu = User::factory()->create()->assignRole('tu');
    $this->ta = TahunAjaran::create(['nama' => '2026/2027', 'tanggal_mulai' => '2026-07-13', 'tanggal_selesai' => '2027-06-30', 'status_aktif' => true]);
    $guru = fn (string $nama, string $kode, bool $aktif = true) => Guru::create(['user_id' => User::factory()->create(['name' => $nama])->id, 'kode_guru' => $kode, 'status_aktif' => $aktif]);
    $this->bu_ani = $guru('Ani Fiktif', 'ANI');
    $this->pak_budi = $guru('Budi Fiktif', 'BUD');
    $this->pensiun = $guru('Citra Fiktif', 'CIT', false);

    $this->tambah = fn (array $data, ?TahunAjaran $ta = null) => $this->actingAs($this->tu)->post('/les-ekskul/tahun-ajaran/'.($ta ?? $this->ta)->id.'/kelas', $data);
    $this->ubah = fn (Kelas $k, array $data) => $this->actingAs($this->tu)->put("/les-ekskul/kelas/{$k->id}", $data);
    $this->kelas2A = ['jenjang' => 2, 'nama_kelas' => '2A', 'nama_pelindung' => 'Fran Xavier', 'wali_kelas_id' => $this->bu_ani->id, 'ada_les' => true, 'biaya_les' => 150000];
    $this->isiSiswa = function (Kelas $k) {
        $s = Siswa::create(['nama' => 'Siswa '.Siswa::count()]);
        SiswaKelas::create(['siswa_id' => $s->id, 'kelas_id' => $k->id]);

        return $s;
    };
});

test('TU menambah kelas beserta les tambahannya; Kepala Sekolah dan Bendahara hanya melihat', function () {
    ($this->tambah)($this->kelas2A)->assertSessionHasNoErrors();
    ($this->tambah)(['jenjang' => 2, 'nama_kelas' => '2b', 'nama_pelindung' => 'Monica', 'ada_les' => false])->assertSessionHasNoErrors();

    $kelas2A = Kelas::where('nama_kelas', '2A')->sole();
    expect($kelas2A->lesTambahan->biaya)->toBe(150000)
        ->and($kelas2A->lesTambahan->tahun_ajaran_id)->toBe($this->ta->id)
        ->and(Kelas::where('nama_kelas', '2B')->sole()->lesTambahan)->toBeNull(); // nama kelas dibuat huruf besar

    foreach (['kepala_sekolah', 'bendahara'] as $role) {
        $lihat = User::factory()->create()->assignRole($role);
        $this->actingAs($lihat)->get('/les-ekskul/kelas')->assertInertia(fn (Assert $page) => $page->component('les-ekskul/kelas/index')
            ->where('bisaUbah', false)
            ->has('kelas', 2)
            ->where('kelas.0.nama_lengkap', '2A Fran Xavier')
            ->where('kelas.0.wali_kelas', 'Ani Fiktif')
            ->where('kelas.1.les', null));
        $this->actingAs($lihat)->post("/les-ekskul/tahun-ajaran/{$this->ta->id}/kelas", [...$this->kelas2A, 'nama_kelas' => '2C'])->assertForbidden();
    }
});

test('validasi: nama kelas ganda, satu guru satu kelas, guru nonaktif, biaya les wajib bila ada les', function () {
    ($this->tambah)($this->kelas2A);

    ($this->tambah)([...$this->kelas2A, 'nama_kelas' => '2a', 'wali_kelas_id' => null])->assertSessionHasErrors('nama_kelas');
    ($this->tambah)([...$this->kelas2A, 'nama_kelas' => '2B'])->assertSessionHasErrors(['wali_kelas_id' => 'Ani Fiktif sudah menjadi wali kelas 2A Fran Xavier.']);
    ($this->tambah)([...$this->kelas2A, 'nama_kelas' => '2B', 'wali_kelas_id' => $this->pensiun->id])->assertSessionHasErrors('wali_kelas_id');
    ($this->tambah)([...$this->kelas2A, 'nama_kelas' => '2B', 'wali_kelas_id' => null, 'biaya_les' => null])->assertSessionHasErrors('biaya_les');

    expect(Kelas::count())->toBe(1);
});

test('jenjang terkunci bila ada siswa; les dengan pendaftar tidak bisa dihilangkan; biaya baru untuk tagihan sesudahnya', function () {
    ($this->tambah)($this->kelas2A);
    $kelas = Kelas::sole();
    $siswa = ($this->isiSiswa)($kelas);
    $pendaftaran = Pendaftaran::create(['siswa_id' => $siswa->id, 'les_tambahan_id' => $kelas->lesTambahan->id, 'tanggal_daftar' => '2026-08-01']);
    $agustus = app(TagihanService::class)->buatUntukBulan($pendaftaran, '2026-08-01');

    ($this->ubah)($kelas, [...$this->kelas2A, 'jenjang' => 3])->assertSessionHasErrors('jenjang');
    ($this->ubah)($kelas, [...$this->kelas2A, 'ada_les' => false])->assertSessionHasErrors('ada_les');
    ($this->ubah)($kelas, [...$this->kelas2A, 'biaya_les' => 175000, 'wali_kelas_id' => $this->pak_budi->id])->assertSessionHasNoErrors();

    $september = app(TagihanService::class)->buatUntukBulan($pendaftaran->fresh(), '2026-09-01');
    expect($agustus->fresh()->nominal)->toBe(150000)
        ->and($september->nominal)->toBe(175000)
        ->and($kelas->fresh()->wali_kelas_id)->toBe($this->pak_budi->id);
});

test('hapus hanya kelas yang belum dipakai; dihapus permanen sehingga nama bisa dipakai lagi', function () {
    ($this->tambah)($this->kelas2A);
    ($this->tambah)(['jenjang' => 2, 'nama_kelas' => '2B', 'ada_les' => true, 'biaya_les' => 150000]);
    [$kelas2A, $kelas2B] = [Kelas::where('nama_kelas', '2A')->sole(), Kelas::where('nama_kelas', '2B')->sole()];
    ($this->isiSiswa)($kelas2A);

    $this->actingAs($this->tu)->delete("/les-ekskul/kelas/{$kelas2A->id}")->assertSessionHasErrors('kelas');
    $this->actingAs($this->tu)->delete("/les-ekskul/kelas/{$kelas2B->id}")->assertSessionHasNoErrors();

    expect(Kelas::withTrashed()->where('nama_kelas', '2B')->exists())->toBeFalse()
        ->and(LesTambahan::count())->toBe(1);
    ($this->tambah)(['jenjang' => 2, 'nama_kelas' => '2B', 'ada_les' => false])->assertSessionHasNoErrors();
});

test('samakan biaya les satu jenjang, kelas tanpa les tidak diubah', function () {
    ($this->tambah)($this->kelas2A);
    ($this->tambah)(['jenjang' => 2, 'nama_kelas' => '2B', 'ada_les' => true, 'biaya_les' => 180000]);
    ($this->tambah)(['jenjang' => 2, 'nama_kelas' => '2C', 'ada_les' => false]);
    ($this->tambah)(['jenjang' => 3, 'nama_kelas' => '3A', 'ada_les' => true, 'biaya_les' => 160000]);

    $this->actingAs($this->tu)->post("/les-ekskul/tahun-ajaran/{$this->ta->id}/kelas/samakan-biaya", ['jenjang' => 2, 'biaya' => 170000])->assertSessionHasNoErrors();

    expect(LesTambahan::with('kelas')->get()->mapWithKeys(fn ($l) => [$l->kelas->nama_kelas => $l->biaya])->sortKeys()->all())
        ->toBe(['2A' => 170000, '2B' => 170000, '3A' => 160000])
        ->and(Kelas::where('nama_kelas', '2C')->sole()->lesTambahan)->toBeNull();
});

test('salin dari tahun ajaran lalu: kelas & biaya les ikut, wali kelas dan siswa tidak', function () {
    ($this->tambah)($this->kelas2A);
    ($this->tambah)(['jenjang' => 3, 'nama_kelas' => '3A', 'nama_pelindung' => 'Alphonsus', 'ada_les' => false]);
    ($this->isiSiswa)(Kelas::where('nama_kelas', '2A')->sole());

    $baru = TahunAjaran::create(['nama' => '2027/2028', 'tanggal_mulai' => '2027-07-12', 'tanggal_selesai' => '2028-06-30', 'status_aktif' => false]);
    $this->actingAs($this->tu)->get("/les-ekskul/kelas?tahun={$baru->id}")
        ->assertInertia(fn (Assert $page) => $page->where('sumberSalinan.nama', '2026/2027')->has('kelas', 0));

    $this->actingAs($this->tu)->post("/les-ekskul/tahun-ajaran/{$baru->id}/kelas/salin")->assertSessionHasNoErrors();

    $salinan = Kelas::where('tahun_ajaran_id', $baru->id)->with('lesTambahan')->orderBy('nama_kelas')->get();
    expect($salinan->pluck('nama_lengkap')->all())->toBe(['2A Fran Xavier', '3A Alphonsus'])
        ->and($salinan[0]->lesTambahan->biaya)->toBe(150000)
        ->and($salinan[0]->lesTambahan->tahun_ajaran_id)->toBe($baru->id)
        ->and($salinan[0]->wali_kelas_id)->toBeNull()
        ->and($salinan[0]->siswaKelas()->count())->toBe(0)
        ->and($salinan[1]->lesTambahan)->toBeNull();

    $this->actingAs($this->tu)->post("/les-ekskul/tahun-ajaran/{$baru->id}/kelas/salin")->assertSessionHasErrors('salin');

    // Guru yang wali 2A di tahun lalu boleh menjadi wali di tahun ajaran baru
    ($this->ubah)($salinan[0], ['jenjang' => 2, 'nama_kelas' => '2A', 'wali_kelas_id' => $this->bu_ani->id, 'ada_les' => true, 'biaya_les' => 150000])
        ->assertSessionHasNoErrors();
});
