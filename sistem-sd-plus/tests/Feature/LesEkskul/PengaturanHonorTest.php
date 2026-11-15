<?php

use App\Domains\LesEkskul\Enums\JenisPengajar;
use App\Domains\LesEkskul\Enums\PeranHonor;
use App\Domains\LesEkskul\Enums\TipeKegiatan;
use App\Domains\LesEkskul\Models\Ekstrakurikuler;
use App\Domains\LesEkskul\Models\HonorFlatJenis;
use App\Domains\LesEkskul\Models\LesTambahan;
use App\Domains\LesEkskul\Models\Pendaftaran;
use App\Domains\LesEkskul\Models\PenerimaPeranHonor;
use App\Domains\LesEkskul\Models\PengajarEkskul;
use App\Domains\LesEkskul\Models\SkemaHonor;
use App\Domains\LesEkskul\Services\PengajarEkskulService;
use App\Domains\LesEkskul\Services\PengaturanHonorService;
use App\Models\Kelas;
use App\Models\Siswa;
use App\Models\TahunAjaran;
use App\Models\User;
use Database\Seeders\HonorFlatJenisSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SkemaHonorSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed([RoleSeeder::class, HonorFlatJenisSeeder::class, SkemaHonorSeeder::class]);
    $this->travelTo('2026-11-15 09:00');

    $this->kepsek = User::factory()->create()->assignRole('kepala_sekolah');
    $this->tu = User::factory()->create()->assignRole('tu');
    $this->guru = User::factory()->create(['name' => 'Anci Guru'])->assignRole('guru');

    $this->ta = TahunAjaran::create(['nama' => '2026/2027', 'tanggal_mulai' => '2026-07-13', 'tanggal_selesai' => '2027-06-30', 'status_aktif' => true]);
});

function persenEkskul(array $ubah = []): array
{
    return [
        'persen_pengajar' => 65, 'persen_guru_pendamping' => 0, 'persen_kas_sekolah' => 20, 'persen_penanggung_jawab' => 7,
        'persen_bendahara' => 3.2, 'persen_admin_tu' => 3.2, 'persen_admin_it' => 1.6, ...$ubah,
    ];
}

function pengajar(string $nama, JenisPengajar $jenis, ?User $user = null): PengajarEkskul
{
    return PengajarEkskul::create(['nama' => $nama, 'jenis' => $jenis, 'user_id' => $user?->id, 'no_hp' => '0812']);
}

test('skema awal memakai angka rekap honor 2026', function () {
    $internal = SkemaHonor::berlakuPada(TipeKegiatan::Ekstrakurikuler, JenisPengajar::Internal, today());
    $eksternal = SkemaHonor::berlakuPada(TipeKegiatan::Ekstrakurikuler, JenisPengajar::Eksternal, today());
    $les = SkemaHonor::berlakuPada(TipeKegiatan::LesTambahan, null, today());

    expect((float) $internal->persen_pengajar)->toBe(65.0)
        ->and($internal->persen_guru_pendamping)->toBeNull()
        ->and((float) $eksternal->persen_guru_pendamping)->toBe(5.0)
        ->and((float) $les->persen_yayasan)->toBe(30.0)
        ->and((float) $les->persen_kas_sekolah)->toBe(6.85)
        ->and(HonorFlatJenis::where('kode', 'petugas_kebersihan')->exists())->toBeTrue();
});

test('Kepala Sekolah mengubah skema: total wajib 100%, perubahan di hari yang sama menimpa baris hari itu', function () {
    $simpan = fn (User $u, array $persen) => $this->actingAs($u)->post('/les-ekskul/honor/skema', ['tipe' => 'ekstrakurikuler', 'jenis' => 'internal', 'persen' => $persen]);

    $simpan($this->tu, persenEkskul())->assertForbidden();
    $simpan($this->kepsek, persenEkskul(['persen_pengajar' => 64]))->assertSessionHasErrors('persen');
    $simpan($this->kepsek, persenEkskul(['persen_pengajar' => 64, 'persen_kas_sekolah' => 21]))->assertSessionHasNoErrors();
    $simpan($this->kepsek, persenEkskul(['persen_pengajar' => 63, 'persen_kas_sekolah' => 22]))->assertSessionHasNoErrors();

    $baris = SkemaHonor::where('tipe_kegiatan', TipeKegiatan::Ekstrakurikuler)->where('jenis_pengajar', JenisPengajar::Internal);
    expect($baris->count())->toBe(2) // baris awal + baris hari ini (bukan 3)
        ->and((float) SkemaHonor::berlakuPada(TipeKegiatan::Ekstrakurikuler, JenisPengajar::Internal, today())->persen_pengajar)->toBe(63.0)
        ->and((float) SkemaHonor::berlakuPada(TipeKegiatan::Ekstrakurikuler, JenisPengajar::Internal, '2026-11-14')->persen_pengajar)->toBe(65.0);
});

test('penerima peran, honor flat, dan penerima tanpa akun yang tidak bisa membuka menu', function () {
    $this->actingAs($this->kepsek)->post('/les-ekskul/honor/peran', ['penerima' => ['penanggung_jawab' => $this->kepsek->id, 'admin_tu' => $this->tu->id]]);

    expect(PenerimaPeranHonor::pemegang(PeranHonor::PenanggungJawab, today())->id)->toBe($this->kepsek->id)
        ->and(app(PengaturanHonorService::class)->masalahPeran())->toBe(['Penerima porsi bendahara belum diatur.', 'Penerima porsi admin IT belum diatur.']);

    $this->actingAs($this->tu)->post('/les-ekskul/honor/penerima-tanpa-akun', ['nama' => 'Dicong'])->assertSessionHasNoErrors();
    $dicong = User::where('name', 'Dicong')->sole();
    expect($dicong->getRoleNames())->toBeEmpty();
    $this->actingAs($dicong)->get('/les-ekskul/periode')->assertForbidden();

    $jenis = HonorFlatJenis::byKode('petugas_kebersihan');
    $this->actingAs($this->tu)->post("/les-ekskul/honor/flat/{$jenis->id}", ['nominal' => 150000])->assertForbidden();
    $this->actingAs($this->kepsek)->post("/les-ekskul/honor/flat/{$jenis->id}", [
        'nominal' => 150000,
        'penerima' => [['user_id' => $dicong->id, 'jumlah' => 2]],
    ])->assertSessionHasNoErrors();

    $pengaturan = app(PengaturanHonorService::class)->honorFlatBerlaku($jenis, $this->ta);
    expect($pengaturan->nominal_per_guru)->toBe(150000)
        ->and($pengaturan->penerima->sole()->pivot->jumlah)->toBe(2);
});

test('TU menambah pengajar internal dan eksternal; satu akun hanya satu pengajar internal', function () {
    $this->actingAs($this->tu)->post('/les-ekskul/honor/pengajar', ['jenis' => 'internal', 'user_id' => $this->guru->id, 'no_hp' => '0811'])->assertSessionHasNoErrors();
    $this->actingAs($this->tu)->post('/les-ekskul/honor/pengajar', ['jenis' => 'internal', 'user_id' => $this->guru->id, 'no_hp' => '0811'])->assertSessionHasErrors('user_id');
    $this->actingAs($this->tu)->post('/les-ekskul/honor/pengajar', ['jenis' => 'eksternal', 'nama' => '', 'no_hp' => '0812'])->assertSessionHasErrors('nama');
    $this->actingAs($this->tu)->post('/les-ekskul/honor/pengajar', ['jenis' => 'eksternal', 'nama' => 'Tanpa HP'])->assertSessionHasErrors('no_hp');
    $this->actingAs($this->tu)->post('/les-ekskul/honor/pengajar', ['jenis' => 'eksternal', 'nama' => 'Ariadi', 'no_hp' => '0812'])->assertSessionHasNoErrors();

    expect(PengajarEkskul::where('jenis', JenisPengajar::Internal)->sole()->nama)->toBe('Anci Guru')
        ->and(PengajarEkskul::where('jenis', JenisPengajar::Eksternal)->sole()->user_id)->toBeNull();
});

test('kelengkapan data honor: per kelas, pengajar eksternal wajib pendamping, siswa belum di-assign, dan pengajar campuran', function () {
    $tari = Ekstrakurikuler::create(['tahun_ajaran_id' => $this->ta->id, 'nama' => 'Tari', 'biaya' => 120000, 'honor_per_kelas_paralel' => true]);
    $tari1 = Ekstrakurikuler::create(['tahun_ajaran_id' => $this->ta->id, 'nama' => 'Tari 1', 'biaya' => 120000, 'parent_ekstrakurikuler_id' => $tari->id]);
    $tari2 = Ekstrakurikuler::create(['tahun_ajaran_id' => $this->ta->id, 'nama' => 'Tari 2', 'biaya' => 120000, 'parent_ekstrakurikuler_id' => $tari->id]);
    Pendaftaran::create(['siswa_id' => Siswa::create(['nama' => 'Belum Assign'])->id, 'ekstrakurikuler_id' => $tari->id, 'tanggal_daftar' => '2026-07-20']);

    $anci = pengajar('Anci', JenisPengajar::Internal, $this->guru);
    $ariadi = pengajar('Ariadi', JenisPengajar::Eksternal);
    $this->actingAs($this->tu)->post("/les-ekskul/honor/kegiatan/{$tari1->id}/pengajar", ['pengajar_ids' => [$anci->id]]);
    $this->actingAs($this->tu)->post("/les-ekskul/honor/kegiatan/{$tari2->id}/pengajar", ['pengajar_ids' => [$ariadi->id]]);

    $masalah = fn () => app(PengajarEkskulService::class)->masalahKegiatan($tari->fresh());

    expect($masalah())->toBe([
        'Tari 2: pengajar eksternal wajib punya guru pendamping.',
        '1 siswa Tari belum di-assign ke kelas; honor per kelas butuh kelas setiap siswa.',
    ]);

    $this->actingAs($this->tu)->post("/les-ekskul/honor/kegiatan/{$tari2->id}/pendamping", ['user_ids' => [$this->guru->id]])->assertForbidden();
    $this->actingAs($this->kepsek)->post("/les-ekskul/honor/kegiatan/{$tari2->id}/pendamping", ['user_ids' => [$this->guru->id]])->assertSessionHasNoErrors();
    Pendaftaran::sole()->update(['ekstrakurikuler_id' => $tari1->id]);
    expect($masalah())->toBe([]);

    // Bila digabung, Anci (internal) dan Ariadi (eksternal) masuk satu perhitungan: ditahan, tidak ditebak
    $this->actingAs($this->tu)->post("/les-ekskul/honor/kegiatan/{$tari->id}/mode", ['per_kelas' => false]);
    expect($masalah())->toBe(['Tari: pengajar campuran internal dan eksternal; pisahkan per kelas atau samakan jenisnya.']);

    $basket = Ekstrakurikuler::create(['tahun_ajaran_id' => $this->ta->id, 'nama' => 'Basket', 'biaya' => 80000]);
    expect(app(PengajarEkskulService::class)->masalahKegiatan($basket))->toBe(['Basket: belum ada pengajar.']);
});

test('les tambahan tanpa wali kelas ditandai belum lengkap', function () {
    $kelas = Kelas::create(['tahun_ajaran_id' => $this->ta->id, 'jenjang' => 2, 'nama_kelas' => '2A']);
    LesTambahan::create(['kelas_id' => $kelas->id, 'biaya' => 180000]);

    expect(app(PengajarEkskulService::class)->masalahLes($this->ta))->toHaveCount(1);
});

test('halaman pengaturan honor bisa dilihat Bendahara tanpa hak mengubah', function () {
    $bendahara = User::factory()->create()->assignRole('bendahara');

    $this->actingAs($bendahara)->get('/les-ekskul/honor/skema')
        ->assertInertia(fn (Assert $page) => $page->component('les-ekskul/honor/skema')->where('bisaUbah', false)->has('skema', 3)->has('honorFlat', 5));
    $this->actingAs($bendahara)->get('/les-ekskul/honor/pengajar')
        ->assertInertia(fn (Assert $page) => $page->component('les-ekskul/honor/pengajar')->where('bisaUbah', false));
    $this->actingAs($bendahara)->post('/les-ekskul/honor/pengajar', ['jenis' => 'eksternal', 'nama' => 'X'])->assertForbidden();
});
