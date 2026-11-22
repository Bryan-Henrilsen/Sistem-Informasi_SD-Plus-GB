<?php

use App\Models\Guru;
use App\Models\JenisTugas;
use App\Models\Kelas;
use App\Models\MataPelajaran;
use App\Models\TahunAjaran;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

// Mata Pelajaran & Jenis Tugas, mapel yang diampu guru, dan penyaringan kisi-kisi (Ringkasan v1.11 #47)
beforeEach(function () {
    $this->seed(RoleSeeder::class);

    $this->waka = User::factory()->create()->assignRole('waka_kurikulum');
    $this->kepsek = User::factory()->create()->assignRole('kepala_sekolah');
    $this->ta = TahunAjaran::create(['nama' => '2026/2027', 'tanggal_mulai' => '2026-07-13', 'tanggal_selesai' => '2027-06-30', 'status_aktif' => true]);
    foreach (['utama' => 'Mengajar Utama', 'damping' => 'Damping', 'piket' => 'Piket'] as $kode => $nama) {
        JenisTugas::create(['kode' => $kode, 'nama' => $nama]);
    }
    $this->mtk = MataPelajaran::create(['kode' => 'MTK', 'nama' => 'Matematika']);
    $this->bi = MataPelajaran::create(['kode' => 'BI', 'nama' => 'Bahasa Indonesia']);

    $this->userGuru = User::factory()->create(['name' => 'Guru Fiktif', 'email' => 'guru@contoh.test'])->assignRole('guru');
    $this->guru = Guru::create(['user_id' => $this->userGuru->id, 'kode_guru' => 'GF']);
});

test('Waka Kurikulum menambah & mengubah mapel; kode huruf besar dan unik; TU tidak boleh', function () {
    $tu = User::factory()->create()->assignRole('tu');
    $this->actingAs($tu)->get('/les-ekskul/mata-pelajaran')->assertForbidden();

    $this->actingAs($this->waka)->post('/les-ekskul/mata-pelajaran', ['kode' => 'ipas', 'nama' => 'IPAS'])->assertSessionHasNoErrors();
    $this->actingAs($this->waka)->post('/les-ekskul/mata-pelajaran', ['kode' => 'mtk', 'nama' => 'Mat lagi'])->assertSessionHasErrors('kode');
    $this->actingAs($this->kepsek)->put("/les-ekskul/mata-pelajaran/{$this->bi->id}", ['kode' => 'BIND', 'nama' => 'Bahasa Indonesia'])->assertSessionHasNoErrors();

    expect(MataPelajaran::where('nama', 'IPAS')->sole()->kode)->toBe('IPAS')
        ->and($this->bi->fresh()->kode)->toBe('BIND');

    $this->actingAs($this->waka)->get('/les-ekskul/mata-pelajaran')->assertInertia(fn (Assert $page) => $page
        ->component('les-ekskul/mata-pelajaran')
        ->has('mapel', 3)
        ->has('jenisTugas', 3));
});

test('hapus hanya mapel yang belum dipakai; jenis tugas hanya ganti nama', function () {
    $this->guru->mataPelajaran()->attach($this->mtk->id);

    $this->actingAs($this->waka)->delete("/les-ekskul/mata-pelajaran/{$this->mtk->id}")->assertSessionHasErrors('mapel');
    $this->actingAs($this->waka)->delete("/les-ekskul/mata-pelajaran/{$this->bi->id}")->assertSessionHasNoErrors();
    expect(MataPelajaran::pluck('kode')->all())->toBe(['MTK']);

    $piket = JenisTugas::where('kode', 'piket')->sole();
    $this->actingAs($this->waka)->put("/les-ekskul/jenis-tugas/{$piket->id}", ['nama' => 'Piket Pagi', 'kode' => 'lain'])->assertSessionHasNoErrors();
    expect($piket->fresh()->nama)->toBe('Piket Pagi')->and($piket->fresh()->kode)->toBe('piket');
});

test('Kepala Sekolah mengatur mapel yang diampu guru; mapel nonaktif tidak bisa ditambahkan baru tetapi yang lama dipertahankan', function () {
    $data = ['nama' => 'Guru Fiktif', 'email' => 'guru@contoh.test', 'peran' => ['guru'], 'kode_guru' => 'GF'];
    $ubah = fn (array $mapel) => $this->actingAs($this->kepsek)->put("/les-ekskul/akun-staf/{$this->userGuru->id}", [...$data, 'mapel_ids' => $mapel]);

    $ubah([$this->mtk->id])->assertSessionHasNoErrors();
    expect($this->guru->mataPelajaran()->pluck('kode')->all())->toBe(['MTK']);

    $this->actingAs($this->waka)->post("/les-ekskul/mata-pelajaran/{$this->mtk->id}/aktif", ['aktif' => false])->assertSessionHasNoErrors();
    $this->actingAs($this->waka)->post("/les-ekskul/mata-pelajaran/{$this->bi->id}/aktif", ['aktif' => false])->assertSessionHasNoErrors();

    $ubah([$this->mtk->id, $this->bi->id])->assertSessionHasNoErrors(); // BI nonaktif & baru: diabaikan; MTK nonaktif tapi sudah ada: tetap
    expect($this->guru->mataPelajaran()->pluck('kode')->all())->toBe(['MTK']);

    $this->actingAs($this->kepsek)->get('/les-ekskul/akun-staf')
        ->assertInertia(fn (Assert $page) => $page->where('akun', fn ($a) => collect($a)->firstWhere('kode_guru', 'GF')['mapel'] == ['MTK']));
});

test('kisi-kisi Jadwal Guru: mapel guru dikirim untuk penyaringan, mapel nonaktif tidak ditawarkan kecuali sudah dipakai', function () {
    $ipas = MataPelajaran::create(['kode' => 'IPAS', 'nama' => 'IPAS']);
    $this->guru->mataPelajaran()->attach($this->mtk->id);
    $kelas = Kelas::create(['tahun_ajaran_id' => $this->ta->id, 'jenjang' => 2, 'nama_kelas' => '2A']);
    DB::table('kisi_kisi_tugas')->insert([
        'tahun_ajaran_id' => $this->ta->id, 'guru_id' => $this->guru->id, 'jenis_tugas_id' => JenisTugas::byKode('utama')->id,
        'kelas_id' => $kelas->id, 'mapel_id' => $ipas->id, 'jumlah_jam' => 2,
    ]);
    $ipas->update(['aktif' => false]); // nonaktif tetapi sudah dipakai kisi-kisi tahun ini
    $this->bi->update(['aktif' => false]); // nonaktif dan belum dipakai

    $this->actingAs($this->waka)->get('/jadwal-guru/kisi-kisi')->assertInertia(fn (Assert $page) => $page
        ->component('jadwal-guru/KisiKisiTugas')
        ->where('guruList.0.mapel_ids', [$this->mtk->id])
        ->where('mapelList', fn ($m) => collect($m)->pluck('kode')->sort()->values()->all() === ['IPAS', 'MTK']));
});
