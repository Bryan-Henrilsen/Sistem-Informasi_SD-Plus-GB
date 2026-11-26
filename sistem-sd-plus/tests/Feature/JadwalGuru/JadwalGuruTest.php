<?php

use App\Domains\JadwalGuru\Models\JadwalMengajar;
use App\Domains\JadwalGuru\Models\KisiKisiTugas;
use App\Domains\JadwalGuru\Models\WaktuPelajaran;
use App\Domains\JadwalGuru\Services\WaktuPelajaranService;
use App\Models\Guru;
use App\Models\JenisTugas;
use App\Models\Kelas;
use App\Models\MataPelajaran;
use App\Models\TahunAjaran;
use App\Models\User;
use Database\Seeders\JenisTugasSeeder;
use Database\Seeders\RoleSeeder;
use Inertia\Testing\AssertableInertia as Assert;

// Aturan modul Jadwal Guru (PRD B.3, Pengembang 2) yang dikunci test oleh Pengembang 1 (Ringkasan v1.11 #52).
// Test hanya memeriksa perilaku yang sudah ada; tidak mengubah aturan modul
beforeEach(function () {
    $this->seed([RoleSeeder::class, JenisTugasSeeder::class]);

    $this->waka = User::factory()->create()->assignRole('waka_kurikulum');
    $this->ta = TahunAjaran::create(['nama' => '2026/2027', 'tanggal_mulai' => '2026-07-13', 'tanggal_selesai' => '2027-06-30', 'status_aktif' => true]);
    $this->kelas1 = Kelas::create(['tahun_ajaran_id' => $this->ta->id, 'jenjang' => 1, 'nama_kelas' => '1A']);
    $this->kelas4 = Kelas::create(['tahun_ajaran_id' => $this->ta->id, 'jenjang' => 4, 'nama_kelas' => '4A']);
    $this->mapel = MataPelajaran::create(['kode' => 'MTK', 'nama' => 'Matematika']);

    // Slot: jam 1 KBM Senin–Selasa untuk semua jenjang, jam 2 KBM Senin khusus kelas 1–2, istirahat Senin
    $layanan = app(WaktuPelajaranService::class);
    $this->jam1 = $layanan->store($this->ta->id, ['urutan' => 1, 'jam_ke' => 1, 'nama' => 'Jam 1', 'jam_mulai' => '07:00:00', 'jam_selesai' => '07:35:00', 'jenis_kegiatan' => 'kbm', 'jenjang_min' => 1, 'jenjang_max' => 6, 'hari' => [1, 2]]);
    $this->jam2 = $layanan->store($this->ta->id, ['urutan' => 2, 'jam_ke' => 2, 'nama' => 'Jam 2', 'jam_mulai' => '07:35:00', 'jam_selesai' => '08:10:00', 'jenis_kegiatan' => 'kbm', 'jenjang_min' => 1, 'jenjang_max' => 2, 'hari' => [1]]);
    $this->istirahat = $layanan->store($this->ta->id, ['urutan' => 3, 'jam_ke' => null, 'nama' => 'Istirahat', 'jam_mulai' => '08:10:00', 'jam_selesai' => '08:25:00', 'jenis_kegiatan' => 'istirahat', 'jenjang_min' => 1, 'jenjang_max' => 6, 'hari' => [1]]);
});

function jgGuru(string $kode): Guru
{
    return Guru::create(['user_id' => User::factory()->create()->id, 'kode_guru' => $kode]);
}

function jgKisi(Guru $guru, string $jenis, ?Kelas $kelas = null, int $jam = 2): KisiKisiTugas
{
    return KisiKisiTugas::create([
        'tahun_ajaran_id' => test()->ta->id, 'guru_id' => $guru->id, 'jenis_tugas_id' => JenisTugas::byKode($jenis)->id,
        'kelas_id' => $kelas?->id, 'mapel_id' => $kelas ? test()->mapel->id : null, 'jumlah_jam' => $jam,
    ]);
}

function jgTempatkan(KisiKisiTugas $kisi, WaktuPelajaran $slot, int $hari, ?int $parent = null)
{
    return test()->actingAs(test()->waka)->post('/jadwal-guru/pembagian/assign', [
        'kisi_kisi_tugas_id' => $kisi->id, 'waktu_pelajaran_id' => $slot->id, 'hari' => $hari, 'parent_jadwal_id' => $parent,
    ]);
}

test('hanya Waka Kurikulum dan Kepala Sekolah yang membuka modul Jadwal Guru', function () {
    foreach (['/jadwal-guru/waktu-pelajaran', '/jadwal-guru/kisi-kisi', '/jadwal-guru/pembagian', '/jadwal-guru/hasil'] as $url) {
        $this->actingAs($this->waka)->get($url)->assertOk();
        $this->actingAs(User::factory()->create()->assignRole('kepala_sekolah'))->get($url)->assertOk();
        $this->actingAs(User::factory()->create()->assignRole('guru'))->get($url)->assertForbidden();
        $this->actingAs(User::factory()->create()->assignRole('tu'))->get($url)->assertForbidden();
    }
});

test('menyimpan waktu pelajaran memberi toast berhasil; slot yang sudah dipakai jadwal tidak bisa dihapus dan hanya jamnya yang boleh diubah', function () {
    $this->actingAs($this->waka)->post('/jadwal-guru/waktu-pelajaran', [
        'urutan' => 4, 'jam_ke' => 3, 'nama' => 'Jam 3', 'jam_mulai' => '08:25', 'jam_selesai' => '09:00',
        'jenis_kegiatan' => 'kbm', 'jenjang_min' => 1, 'jenjang_max' => 6, 'hari' => [1, 2, 3],
    ])->assertSessionHasNoErrors()->assertInertiaFlash('toast.type', 'success');
    expect(WaktuPelajaran::where('nama', 'Jam 3')->first()->hariBerlaku()->count())->toBe(3);

    // Jam selesai harus sesudah jam mulai
    $this->actingAs($this->waka)->post('/jadwal-guru/waktu-pelajaran', [
        'urutan' => 5, 'jam_ke' => 4, 'nama' => 'Jam 4', 'jam_mulai' => '09:00', 'jam_selesai' => '08:50',
        'jenis_kegiatan' => 'kbm', 'jenjang_min' => 1, 'jenjang_max' => 6, 'hari' => [1],
    ])->assertSessionHasErrors('jam_selesai');

    jgTempatkan(jgKisi(jgGuru('G1'), 'utama', $this->kelas1), $this->jam1, 1)->assertSessionHasNoErrors();

    $this->actingAs($this->waka)->delete("/jadwal-guru/waktu-pelajaran/{$this->jam1->id}")->assertSessionHasErrors('slot');
    $ubah = ['urutan' => 1, 'jam_ke' => 1, 'nama' => 'Jam 1', 'jam_mulai' => '07:05', 'jam_selesai' => '07:40', 'jenis_kegiatan' => 'kbm', 'jenjang_min' => 1, 'jenjang_max' => 6, 'hari' => [1, 2]];
    $this->actingAs($this->waka)->put("/jadwal-guru/waktu-pelajaran/{$this->jam1->id}", [...$ubah, 'nama' => 'Jam Pertama'])->assertSessionHasErrors('slot');
    $this->actingAs($this->waka)->put("/jadwal-guru/waktu-pelajaran/{$this->jam1->id}", [...$ubah, 'hari' => [1]])->assertSessionHasErrors('hari');
    $this->actingAs($this->waka)->put("/jadwal-guru/waktu-pelajaran/{$this->jam1->id}", $ubah)->assertSessionHasNoErrors();
    expect($this->jam1->fresh()->jam_mulai)->toStartWith('07:05');

    $this->actingAs($this->waka)->delete("/jadwal-guru/waktu-pelajaran/{$this->istirahat->id}")->assertSessionHasNoErrors();
});

test('kisi-kisi utama dibatasi kuota jam kelas; alokasi berjadwal tidak bisa dihapus atau diturunkan di bawah jadwal terpasang', function () {
    $guru = jgGuru('G1');
    $kirim = fn (array $isi) => $this->actingAs($this->waka)->post('/jadwal-guru/kisi-kisi', [
        'guru_id' => $guru->id, 'jenis_tugas' => 'utama', 'kelas_id' => $this->kelas1->id, 'mapel_id' => $this->mapel->id, ...$isi,
    ]);

    // Kuota kelas 1 = slot KBM yang berlaku untuk jenjang 1: jam 1 (Senin, Selasa) + jam 2 (Senin) = 3 jam
    $kirim(['jumlah_jam' => 4])->assertSessionHasErrors('jumlah_jam');
    $kirim(['jumlah_jam' => 3])->assertSessionHasNoErrors()->assertInertiaFlash('toast.message', 'Alokasi tugas berhasil ditambahkan.');

    // Utama wajib kelas & mapel; piket tidak boleh punya kelas
    $this->actingAs($this->waka)->post('/jadwal-guru/kisi-kisi', ['guru_id' => $guru->id, 'jenis_tugas' => 'utama', 'jumlah_jam' => 1])->assertSessionHasErrors(['kelas_id', 'mapel_id']);
    $this->actingAs($this->waka)->post('/jadwal-guru/kisi-kisi', ['guru_id' => $guru->id, 'jenis_tugas' => 'piket', 'kelas_id' => $this->kelas1->id, 'jumlah_jam' => 1])->assertSessionHasErrors('kelas_id');

    $kisi = KisiKisiTugas::sole();
    jgTempatkan($kisi, $this->jam1, 1)->assertSessionHasNoErrors();
    jgTempatkan($kisi, $this->jam1, 2)->assertSessionHasNoErrors();

    $isi = ['guru_id' => $guru->id, 'jenis_tugas' => 'utama', 'kelas_id' => $this->kelas1->id, 'mapel_id' => $this->mapel->id];
    $this->actingAs($this->waka)->put("/jadwal-guru/kisi-kisi/{$kisi->id}", [...$isi, 'jumlah_jam' => 1])->assertSessionHasErrors('jumlah_jam');
    $this->actingAs($this->waka)->delete("/jadwal-guru/kisi-kisi/{$kisi->id}")->assertSessionHasErrors('allocation');
});

test('penempatan jadwal utama menolak slot yang tidak berlaku, sisa jam habis, dan bentrok guru maupun kelas', function () {
    $guruA = jgGuru('GA');
    $guruB = jgGuru('GB');
    $utamaA = jgKisi($guruA, 'utama', $this->kelas1, 2);
    $utamaB = jgKisi($guruB, 'utama', $this->kelas1, 2);

    jgTempatkan($utamaA, $this->jam2, 2)->assertSessionHasErrors(['message' => 'Slot waktu tersebut tidak berlaku pada hari yang dipilih.']);
    jgTempatkan($utamaA, $this->istirahat, 1)->assertSessionHasErrors(['message' => 'Guru utama hanya dapat ditempatkan pada slot KBM.']);
    jgTempatkan(jgKisi($guruB, 'utama', $this->kelas4, 1), $this->jam2, 1)->assertSessionHasErrors(['message' => 'Slot waktu tidak berlaku untuk jenjang kelas ini.']);

    jgTempatkan($utamaA, $this->jam1, 1)->assertSessionHasNoErrors()->assertInertiaFlash('toast.message', 'Jadwal berhasil ditempatkan.');
    jgTempatkan($utamaB, $this->jam1, 1)->assertSessionHasErrors(['message' => 'Bentrok: kelas ini sudah memiliki guru utama pada slot tersebut.']);
    jgTempatkan(jgKisi($guruA, 'utama', $this->kelas4, 1), $this->jam1, 1)->assertSessionHasErrors(['message' => 'Bentrok: guru ini sudah memiliki tugas pada slot waktu tersebut.']);

    jgTempatkan($utamaA, $this->jam2, 1)->assertSessionHasNoErrors();
    jgTempatkan($utamaA, $this->jam1, 2)->assertSessionHasErrors(['message' => 'Sisa alokasi jam untuk tugas ini sudah habis.']);
    expect(JadwalMengajar::count())->toBe(2);
});

test('damping menempel pada jadwal utama kelas 1–2 di slot yang sama; menarik jadwal utama ikut menarik dampingnya', function () {
    $utama = jgKisi(jgGuru('GU'), 'utama', $this->kelas1, 2);
    $utama4 = jgKisi(jgGuru('GE'), 'utama', $this->kelas4, 1);
    $damping = jgKisi(jgGuru('GD'), 'damping', null, 3);
    $piket = jgKisi(jgGuru('GP'), 'piket', null, 1);

    jgTempatkan($utama, $this->jam1, 1)->assertSessionHasNoErrors();
    jgTempatkan($utama4, $this->jam1, 2)->assertSessionHasNoErrors();
    $jadwalUtama = JadwalMengajar::where('kelas_id', $this->kelas1->id)->sole();
    $jadwalUtama4 = JadwalMengajar::where('kelas_id', $this->kelas4->id)->sole();

    jgTempatkan($damping, $this->jam1, 1)->assertSessionHasErrors(['message' => 'Pilih slot guru utama yang akan ditempeli guru damping.']);
    jgTempatkan($damping, $this->jam2, 1, $jadwalUtama->id)->assertSessionHasErrors(['message' => 'Slot damping harus sama persis dengan hari dan waktu guru utama.']);
    jgTempatkan($damping, $this->jam1, 2, $jadwalUtama4->id)->assertSessionHasErrors(['message' => 'Guru damping hanya dapat ditempatkan pada kelas jenjang 1–2.']);
    jgTempatkan($damping, $this->jam1, 1, $jadwalUtama->id)->assertSessionHasNoErrors();
    expect(JadwalMengajar::where('parent_jadwal_id', $jadwalUtama->id)->sole()->kelas_id)->toBe($this->kelas1->id);

    // Piket tanpa kelas, boleh di slot non-KBM
    jgTempatkan($piket, $this->istirahat, 1)->assertSessionHasNoErrors();

    $this->actingAs($this->waka)->delete("/jadwal-guru/pembagian/{$jadwalUtama->id}")
        ->assertSessionHasNoErrors()->assertInertiaFlash('toast.message', 'Jadwal berhasil ditarik kembali.');
    expect(JadwalMengajar::where('parent_jadwal_id', $jadwalUtama->id)->count())->toBe(0)
        ->and(JadwalMengajar::count())->toBe(2); // utama kelas 4 + piket
});

test('hasil jadwal menampilkan jadwal tahun ajaran aktif', function () {
    jgTempatkan(jgKisi(jgGuru('GU'), 'utama', $this->kelas1, 1), $this->jam1, 1)->assertSessionHasNoErrors();

    $this->actingAs($this->waka)->get('/jadwal-guru/hasil')
        ->assertInertia(fn (Assert $page) => $page->component('jadwal-guru/HasilJadwal')->has('jadwal', 1)->where('jadwal.0.kelas.nama_kelas', '1A'));
});
