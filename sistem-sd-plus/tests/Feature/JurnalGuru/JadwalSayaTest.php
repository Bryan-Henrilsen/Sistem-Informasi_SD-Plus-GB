<?php

use App\Domains\JadwalGuru\Models\JadwalMengajar;
use App\Domains\JadwalGuru\Models\KisiKisiTugas;
use App\Domains\JadwalGuru\Models\WaktuPelajaran;
use App\Domains\LesEkskul\Enums\JenisPengajar;
use App\Domains\LesEkskul\Models\Ekstrakurikuler;
use App\Domains\LesEkskul\Models\JadwalEkskul;
use App\Domains\LesEkskul\Models\LesTambahan;
use App\Domains\LesEkskul\Models\PengajarEkskul;
use App\Models\Guru;
use App\Models\JenisTugas;
use App\Models\Kelas;
use App\Models\MataPelajaran;
use App\Models\TahunAjaran;
use App\Models\User;
use Database\Seeders\JenisTugasSeeder;
use Database\Seeders\RoleSeeder;
use Inertia\Testing\AssertableInertia as Assert;

// Jadwal Saya (PRD B.2 FR-1, Ringkasan v1.11 #51): membaca jadwal_mengajar, ekskul, dan les guru yang login
beforeEach(function () {
    $this->seed([RoleSeeder::class, JenisTugasSeeder::class]);

    $this->ta = TahunAjaran::create(['nama' => '2026/2027', 'tanggal_mulai' => '2026-07-13', 'tanggal_selesai' => '2027-06-30', 'status_aktif' => true]);
    $this->user = User::factory()->create(['name' => 'Ani Guru'])->assignRole('guru');
    $this->guru = Guru::create(['user_id' => $this->user->id, 'kode_guru' => 'ANI']);
});

function slotUji(int $urutan, string $mulai, string $selesai): WaktuPelajaran
{
    return WaktuPelajaran::create([
        'tahun_ajaran_id' => test()->ta->id, 'urutan' => $urutan, 'jam_ke' => $urutan, 'nama' => "Jam ke-{$urutan}",
        'jam_mulai' => $mulai, 'jam_selesai' => $selesai, 'jenis_kegiatan' => 'kbm',
    ]);
}

test('jadwal mengajar per hari urut jam, beserta ekskul yang diajar/didampingi dan les sebagai wali kelas', function () {
    $kelas = Kelas::create(['tahun_ajaran_id' => $this->ta->id, 'jenjang' => 3, 'nama_kelas' => '3A', 'wali_kelas_id' => $this->guru->id]);
    LesTambahan::create(['kelas_id' => $kelas->id, 'biaya' => 180000]);
    $mapel = MataPelajaran::create(['kode' => 'BIN', 'nama' => 'Bahasa Indonesia']);
    [$jam1, $jam2] = [slotUji(1, '07:00', '07:35'), slotUji(2, '07:35', '08:10')];

    $utama = KisiKisiTugas::create(['tahun_ajaran_id' => $this->ta->id, 'guru_id' => $this->guru->id, 'jenis_tugas_id' => JenisTugas::where('kode', 'utama')->value('id'), 'kelas_id' => $kelas->id, 'mapel_id' => $mapel->id, 'jumlah_jam' => 4]);
    $piket = KisiKisiTugas::create(['tahun_ajaran_id' => $this->ta->id, 'guru_id' => $this->guru->id, 'jenis_tugas_id' => JenisTugas::where('kode', 'piket')->value('id'), 'jumlah_jam' => 1]);
    JadwalMengajar::create(['kisi_kisi_tugas_id' => $utama->id, 'waktu_pelajaran_id' => $jam2->id, 'hari' => 1]);
    JadwalMengajar::create(['kisi_kisi_tugas_id' => $piket->id, 'waktu_pelajaran_id' => $jam1->id, 'hari' => 1]);
    JadwalMengajar::create(['kisi_kisi_tugas_id' => $utama->id, 'waktu_pelajaran_id' => $jam1->id, 'hari' => 3]);

    // Jadwal guru lain tidak ikut
    $lain = Guru::create(['user_id' => User::factory()->create()->id, 'kode_guru' => 'LAIN']);
    $kisiLain = KisiKisiTugas::create(['tahun_ajaran_id' => $this->ta->id, 'guru_id' => $lain->id, 'jenis_tugas_id' => JenisTugas::where('kode', 'piket')->value('id'), 'jumlah_jam' => 1]);
    JadwalMengajar::create(['kisi_kisi_tugas_id' => $kisiLain->id, 'waktu_pelajaran_id' => $jam2->id, 'hari' => 2]);

    // Ekskul: pengajar Coding (internal), pendamping Basket
    $coding = Ekstrakurikuler::create(['tahun_ajaran_id' => $this->ta->id, 'nama' => 'Coding', 'biaya' => 100000]);
    JadwalEkskul::create(['ekstrakurikuler_id' => $coding->id, 'hari' => 5, 'jam_mulai' => '13:00', 'jam_selesai' => '14:30', 'lokasi' => 'Lab']);
    $coding->pengajar()->attach(PengajarEkskul::create(['nama' => 'Ani Guru', 'jenis' => JenisPengajar::Internal, 'user_id' => $this->user->id, 'no_hp' => '0812'])->id);
    $basket = Ekstrakurikuler::create(['tahun_ajaran_id' => $this->ta->id, 'nama' => 'Basket', 'biaya' => 100000]);
    $basket->pendamping()->attach($this->user->id);

    $this->actingAs($this->user)->get('/jurnal-guru/jadwal-saya')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('jurnal-guru/jadwal-saya')
            ->has('hari', 2)
            ->where('hari.0.nama', 'Senin')
            ->where('hari.0.jadwal.0.jenis', 'piket')
            ->where('hari.0.jadwal.1.mapel', 'Bahasa Indonesia')
            ->where('hari.0.jadwal.1.kelas', '3A')
            ->where('hari.1.nama', 'Rabu')
            ->where('ekskul.0.nama', 'Basket')
            ->where('ekskul.0.peran', 'Pendamping')
            ->where('ekskul.1.nama', 'Coding')
            ->where('ekskul.1.peran', 'Pengajar')
            ->where('ekskul.1.jadwal.0.hari', 'Jumat')
            ->where('les.0.kelas', '3A'));
});

test('Jadwal Saya khusus guru; jadwal kosong tetap bisa dibuka', function () {
    $this->actingAs($this->user)->get('/jurnal-guru/jadwal-saya')->assertOk()->assertInertia(fn (Assert $page) => $page->has('hari', 0));

    $this->actingAs(User::factory()->create()->assignRole('kepala_sekolah'))->get('/jurnal-guru/jadwal-saya')->assertForbidden();
    $this->actingAs(User::factory()->create()->assignRole('guru'))->get('/jurnal-guru/jadwal-saya')->assertForbidden(); // tanpa data guru
});
