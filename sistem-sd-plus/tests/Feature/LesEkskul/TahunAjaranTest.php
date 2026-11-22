<?php

use App\Domains\LesEkskul\Enums\StatusPendaftaran;
use App\Domains\LesEkskul\Models\Ekstrakurikuler;
use App\Domains\LesEkskul\Models\Pendaftaran;
use App\Domains\LesEkskul\Models\Tagihan;
use App\Enums\StatusSekolah;
use App\Models\Kelas;
use App\Models\Semester;
use App\Models\Siswa;
use App\Models\SiswaKelas;
use App\Models\TahunAjaran;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Inertia\Testing\AssertableInertia as Assert;

// Tahun Ajaran & Semester dan kenaikan kelas massal (Ringkasan v1.11 #45)
beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->travelTo('2027-06-20 09:00');

    $this->kepsek = User::factory()->create()->assignRole('kepala_sekolah');
    $this->tu = User::factory()->create()->assignRole('tu');
    $this->lama = TahunAjaran::create(['nama' => '2026/2027', 'tanggal_mulai' => '2026-07-13', 'tanggal_selesai' => '2027-06-30', 'status_aktif' => true]);
    $this->lama->semester()->createMany([
        ['urutan' => 1, 'nama' => 'Semester 1', 'tanggal_mulai' => '2026-07-13', 'tanggal_selesai' => '2026-12-31'],
        ['urutan' => 2, 'nama' => 'Semester 2', 'tanggal_mulai' => '2027-01-01', 'tanggal_selesai' => '2027-06-30'],
    ]);

    $this->baruData = [
        'nama' => '2027/2028', 'tanggal_mulai' => '2027-07-01', 'tanggal_selesai' => '2028-06-30',
        'semester' => [
            ['tanggal_mulai' => '2027-07-01', 'tanggal_selesai' => '2027-12-31'],
            ['tanggal_mulai' => '2028-01-01', 'tanggal_selesai' => '2028-06-30'],
        ],
    ];
    $this->buatBaru = fn (array $ubah = []) => $this->actingAs($this->kepsek)->post('/les-ekskul/tahun-ajaran', [...$this->baruData, ...$ubah]);
    $this->kelas = fn (TahunAjaran $ta, int $jenjang, string $nama) => Kelas::create(['tahun_ajaran_id' => $ta->id, 'jenjang' => $jenjang, 'nama_kelas' => $nama]);
    $this->siswa = function (string $nama, Kelas $kelas) {
        $s = Siswa::create(['nama' => $nama]);
        SiswaKelas::create(['siswa_id' => $s->id, 'kelas_id' => $kelas->id]);

        return $s;
    };
});

test('Kepala Sekolah membuat tahun ajaran dari usulan; TU hanya melihat', function () {
    $this->actingAs($this->tu)->get('/les-ekskul/tahun-ajaran')->assertInertia(fn (Assert $page) => $page
        ->component('les-ekskul/tahun-ajaran/index')
        ->where('bisaUbah', false)
        ->where('bisaNaikkan', true)
        ->where('usulan.nama', '2027/2028')
        ->where('usulan.tanggal_mulai', '2027-07-01')
        ->where('usulan.semester.1.tanggal_mulai', '2028-01-01'));
    $this->actingAs($this->tu)->post('/les-ekskul/tahun-ajaran', $this->baruData)->assertForbidden();

    ($this->buatBaru)()->assertSessionHasNoErrors();

    $baru = TahunAjaran::where('nama', '2027/2028')->sole();
    expect($baru->status_aktif)->toBeFalse()
        ->and($baru->semester()->orderBy('urutan')->pluck('tanggal_selesai')->map->toDateString()->all())->toBe(['2027-12-31', '2028-06-30']);
});

test('validasi nama, irisan rentang, dan semester; tanggal terkunci setelah punya kelas', function () {
    ($this->buatBaru)(['nama' => '2027/2029'])->assertSessionHasErrors('nama');
    ($this->buatBaru)(['nama' => '2026/2027'])->assertSessionHasErrors('nama');
    ($this->buatBaru)(['tanggal_mulai' => '2027-06-01'])->assertSessionHasErrors('tanggal_mulai'); // beririsan dengan 2026/2027
    ($this->buatBaru)(['semester' => [
        ['tanggal_mulai' => '2027-07-01', 'tanggal_selesai' => '2028-01-15'],
        ['tanggal_mulai' => '2028-01-01', 'tanggal_selesai' => '2028-06-30'],
    ]])->assertSessionHasErrors('semester');
    expect(TahunAjaran::count())->toBe(1);

    ($this->buatBaru)();
    $baru = TahunAjaran::where('nama', '2027/2028')->sole();
    ($this->kelas)($baru, 1, '1A');

    $ubah = fn (array $data) => $this->actingAs($this->kepsek)->put("/les-ekskul/tahun-ajaran/{$baru->id}", [...$this->baruData, ...$data]);
    $ubah(['tanggal_mulai' => '2027-07-12', 'semester' => [
        ['tanggal_mulai' => '2027-07-12', 'tanggal_selesai' => '2027-12-31'],
        ['tanggal_mulai' => '2028-01-01', 'tanggal_selesai' => '2028-06-30'],
    ]])->assertSessionHasErrors('tanggal_mulai');
    $ubah(['semester' => [
        ['tanggal_mulai' => '2027-07-12', 'tanggal_selesai' => '2027-12-20'],
        ['tanggal_mulai' => '2028-01-05', 'tanggal_selesai' => '2028-06-30'],
    ]])->assertSessionHasNoErrors(); // tanggal semester tetap boleh diubah

    expect(Semester::where('tahun_ajaran_id', $baru->id)->where('urutan', 2)->sole()->tanggal_mulai->toDateString())->toBe('2028-01-05');
});

test('aktifkan butuh kelas; status aktif berpindah; hapus hanya tahun ajaran kosong dan tidak aktif', function () {
    ($this->buatBaru)();
    $baru = TahunAjaran::where('nama', '2027/2028')->sole();

    $this->actingAs($this->kepsek)->post("/les-ekskul/tahun-ajaran/{$baru->id}/aktifkan")->assertSessionHasErrors('aktifkan');
    $this->actingAs($this->tu)->post("/les-ekskul/tahun-ajaran/{$baru->id}/aktifkan")->assertForbidden();

    $kelasLama = ($this->kelas)($this->lama, 1, '1A');
    ($this->siswa)('Belum Naik', $kelasLama);
    ($this->kelas)($baru, 2, '2A');
    $this->actingAs($this->kepsek)->get('/les-ekskul/tahun-ajaran')
        ->assertInertia(fn (Assert $page) => $page->where('berikutnya.nama', '2027/2028')->where('berikutnya.siswa_belum_berkelas', 1)->where('berikutnya.hambatan', []));

    $this->actingAs($this->kepsek)->post("/les-ekskul/tahun-ajaran/{$baru->id}/aktifkan")->assertSessionHasNoErrors();
    expect(TahunAjaran::aktif()->sole()->nama)->toBe('2027/2028');

    $this->actingAs($this->kepsek)->delete("/les-ekskul/tahun-ajaran/{$baru->id}")->assertSessionHasErrors('tahun_ajaran'); // aktif
    $this->actingAs($this->kepsek)->delete("/les-ekskul/tahun-ajaran/{$this->lama->id}")->assertSessionHasErrors('tahun_ajaran'); // punya kelas

    $kosong = TahunAjaran::create(['nama' => '2028/2029', 'tanggal_mulai' => '2028-07-01', 'tanggal_selesai' => '2029-06-30']);
    $kosong->semester()->create(['urutan' => 1, 'nama' => 'Semester 1', 'tanggal_mulai' => '2028-07-01', 'tanggal_selesai' => '2028-12-31']);
    $this->actingAs($this->kepsek)->delete("/les-ekskul/tahun-ajaran/{$kosong->id}")->assertSessionHasNoErrors();
    expect(TahunAjaran::find($kosong->id))->toBeNull()->and(Semester::where('tahun_ajaran_id', $kosong->id)->exists())->toBeFalse();
});

test('kenaikan kelas: naik per kelas, kelas 6 lulus, tinggal kelas, keluar sekolah tidak ikut, aman dijalankan ulang', function () {
    [$l1A, $l6A] = [($this->kelas)($this->lama, 1, '1A'), ($this->kelas)($this->lama, 6, '6A')];
    $andi = ($this->siswa)('Andi', $l1A);
    $budi = ($this->siswa)('Budi', $l1A);
    $citra = ($this->siswa)('Citra', $l6A);
    $dodi = ($this->siswa)('Dodi', $l1A);
    $dodi->update(['status_sekolah' => StatusSekolah::PindahSekolah]);

    ($this->buatBaru)();
    $baru = TahunAjaran::where('nama', '2027/2028')->sole();
    [$b1A, $b2A] = [($this->kelas)($baru, 1, '1A'), ($this->kelas)($baru, 2, '2A')];
    $url = "/les-ekskul/tahun-ajaran/{$baru->id}/kenaikan";

    $this->actingAs($this->tu)->get($url)->assertInertia(fn (Assert $page) => $page->component('les-ekskul/tahun-ajaran/kenaikan')
        ->where('asal.nama', '2026/2027')
        ->where('kelasAsal.0.usulan_tujuan_id', $b2A->id) // 1A → 2A
        ->has('kelasAsal.0.siswa', 2) // Dodi keluar sekolah tidak ikut
        ->where('kelasAsal.1.usulan_tujuan_id', null)); // 6A lulus
    $bendahara = User::factory()->create()->assignRole('bendahara');
    $this->actingAs($bendahara)->get($url)->assertForbidden();

    // Tujuan salah jenjang → seluruhnya dibatalkan
    $this->actingAs($this->tu)->post($url, ['tujuan' => [$l1A->id => $b1A->id]])->assertSessionHasErrors("tujuan.{$l1A->id}");
    expect(SiswaKelas::where('tahun_ajaran_id', $baru->id)->count())->toBe(0);

    $this->actingAs($this->tu)->post($url, ['tujuan' => [$l1A->id => $b2A->id], 'tinggal' => [$budi->id => $b1A->id]])->assertSessionHasNoErrors();

    $kelasBaru = fn (Siswa $s) => SiswaKelas::where('siswa_id', $s->id)->where('tahun_ajaran_id', $baru->id)->value('kelas_id');
    expect($kelasBaru($andi))->toBe($b2A->id)
        ->and($kelasBaru($budi))->toBe($b1A->id)
        ->and($kelasBaru($dodi))->toBeNull()
        ->and($kelasBaru($citra))->toBeNull()
        ->and($citra->fresh()->status_sekolah)->toBe(StatusSekolah::Lulus);

    // Dijalankan ulang: siswa yang sudah berkelas dilewati, tidak ada baris ganda
    $this->actingAs($this->tu)->post($url, ['tujuan' => [$l1A->id => $b2A->id]])->assertSessionHasNoErrors();
    expect(SiswaKelas::where('tahun_ajaran_id', $baru->id)->count())->toBe(2);
});

test('setelah berganti tahun ajaran: tunggakan tahun lalu tetap terlihat orang tua, pendaftaran tahun lalu tidak dihitung aktif', function () {
    $l2A = ($this->kelas)($this->lama, 2, '2A');
    $anak = ($this->siswa)('Anak Uji', $l2A);
    $ortu = User::factory()->create(['email' => null, 'no_hp' => '081234567890'])->assignRole('orang_tua');
    $ortu->anak()->attach($anak->id);
    $basketLama = Ekstrakurikuler::create(['tahun_ajaran_id' => $this->lama->id, 'nama' => 'Basket', 'biaya' => 80000]);
    $daftarLama = Pendaftaran::create(['siswa_id' => $anak->id, 'ekstrakurikuler_id' => $basketLama->id, 'tanggal_daftar' => '2026-08-01']);
    $mei = Tagihan::create(['pendaftaran_id' => $daftarLama->id, 'bulan_tagihan' => '2027-05-01', 'nominal' => 80000]); // belum dibayar

    ($this->buatBaru)();
    $baru = TahunAjaran::where('nama', '2027/2028')->sole();
    $b3A = ($this->kelas)($baru, 3, '3A');
    SiswaKelas::create(['siswa_id' => $anak->id, 'kelas_id' => $b3A->id]);
    $this->actingAs($this->kepsek)->post("/les-ekskul/tahun-ajaran/{$baru->id}/aktifkan")->assertSessionHasNoErrors();
    $this->travelTo('2027-07-15 09:00');

    $this->actingAs($ortu)->get('/ortu/tagihan')->assertInertia(fn (Assert $page) => $page
        ->where('anak.0.tagihan.0.id', $mei->id)
        ->where('anak.0.tagihan.0.tunggakan', true)
        ->where('anak.0.ringkasan.tunggakan', 80000));

    $this->actingAs($this->tu)->get("/les-ekskul/data-siswa?tahun={$baru->id}")
        ->assertInertia(fn (Assert $page) => $page->where('siswa.data.0.kegiatan_aktif', 0));

    // Keluar sekolah di tahun ajaran baru tidak tersandung pendaftaran tahun lalu
    $this->actingAs($this->tu)->post("/les-ekskul/data-siswa/{$anak->id}/keluar", ['tanggal' => '2027-07-20', 'alasan' => 'Pindah kota'])->assertSessionHasNoErrors();
    expect($anak->fresh()->status_sekolah)->toBe(StatusSekolah::PindahSekolah)
        ->and($daftarLama->fresh()->status)->toBe(StatusPendaftaran::Aktif); // data tahun lalu tidak diubah
});
