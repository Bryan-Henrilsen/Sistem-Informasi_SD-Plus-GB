<?php

use App\Domains\LesEkskul\Enums\MetodeTransaksi;
use App\Domains\LesEkskul\Enums\StatusPendaftaran;
use App\Domains\LesEkskul\Models\Ekstrakurikuler;
use App\Domains\LesEkskul\Models\KonfirmasiTransfer;
use App\Domains\LesEkskul\Models\LesTambahan;
use App\Domains\LesEkskul\Models\Pendaftaran;
use App\Domains\LesEkskul\Models\PenyesuaianDana;
use App\Domains\LesEkskul\Models\PeriodeLaporan;
use App\Domains\LesEkskul\Models\Tagihan;
use App\Domains\LesEkskul\Services\TagihanService;
use App\Domains\LesEkskul\Services\TransaksiService;
use App\Enums\StatusSekolah;
use App\Models\Guru;
use App\Models\Kelas;
use App\Models\Siswa;
use App\Models\SiswaKelas;
use App\Models\TahunAjaran;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Inertia\Testing\AssertableInertia as Assert;

// Data master Siswa (Ringkasan v1.11 #44)
beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->travelTo('2026-10-10 09:00');

    $this->tu = User::factory()->create()->assignRole('tu');
    $this->ta = TahunAjaran::create(['nama' => '2026/2027', 'tanggal_mulai' => '2026-07-13', 'tanggal_selesai' => '2027-06-30', 'status_aktif' => true]);
    $this->ta->semester()->createMany([
        ['urutan' => 1, 'nama' => 'Semester 1', 'tanggal_mulai' => '2026-07-13', 'tanggal_selesai' => '2026-12-31'],
        ['urutan' => 2, 'nama' => 'Semester 2', 'tanggal_mulai' => '2027-01-01', 'tanggal_selesai' => '2027-06-30'],
    ]);
    PeriodeLaporan::create(['bulan' => '2026-10-01', 'tanggal_mulai' => '2026-09-23', 'tanggal_selesai' => '2026-10-22']);

    $kelas = fn (int $j, string $n) => Kelas::create(['tahun_ajaran_id' => $this->ta->id, 'jenjang' => $j, 'nama_kelas' => $n]);
    [$this->k2A, $this->k2B, $this->k2C, $this->k3A] = [$kelas(2, '2A'), $kelas(2, '2B'), $kelas(2, '2C'), $kelas(3, '3A')];
    $this->les2A = LesTambahan::create(['kelas_id' => $this->k2A->id, 'biaya' => 150000]);
    $this->les2B = LesTambahan::create(['kelas_id' => $this->k2B->id, 'biaya' => 160000]);
    $this->basket = Ekstrakurikuler::create(['tahun_ajaran_id' => $this->ta->id, 'nama' => 'Basket', 'biaya' => 80000]);
    $this->guru = Guru::create(['user_id' => User::factory()->create(['name' => 'Guru Fiktif'])->id, 'kode_guru' => 'GF', 'status_aktif' => true]);

    $this->url = "/les-ekskul/tahun-ajaran/{$this->ta->id}/data-siswa";
    $this->data = ['nama' => 'Budi Uji', 'jenis_kelamin' => 'L', 'nis' => '1001', 'nisn' => '0012345678', 'tanggal_lahir' => '2019-05-01', 'kelas_id' => $this->k2A->id];
    $this->buatSiswa = function (string $nama, Kelas $kelas) {
        $s = Siswa::create(['nama' => $nama]);
        SiswaKelas::create(['siswa_id' => $s->id, 'kelas_id' => $kelas->id]);

        return $s;
    };
    $this->daftar = fn (Siswa $s, array $kegiatan) => Pendaftaran::create(['siswa_id' => $s->id, 'tanggal_daftar' => '2026-08-01', ...$kegiatan]);
    $this->tagih = function (Pendaftaran $p) {
        foreach (['2026-08-01', '2026-09-01', '2026-10-01', '2026-11-01'] as $b) {
            app(TagihanService::class)->buatUntukBulan($p, $b);
        }
    };
});

test('TU menambah siswa; Kepala Sekolah & Bendahara hanya melihat; NIS dan nama ganda ditolak', function () {
    $this->actingAs($this->tu)->post($this->url, $this->data)->assertSessionHasNoErrors();

    $budi = Siswa::sole();
    expect($budi->nis)->toBe('1001')->and($budi->riwayatKelas()->sole()->kelas_id)->toBe($this->k2A->id);

    $this->actingAs($this->tu)->post($this->url, [...$this->data, 'nama' => 'Ani Uji', 'nisn' => null])->assertSessionHasErrors('nis');
    $this->actingAs($this->tu)->post($this->url, [...$this->data, 'nama' => 'budi uji', 'nis' => null, 'nisn' => null])->assertSessionHasErrors('nama');

    foreach (['kepala_sekolah', 'bendahara'] as $role) {
        $lihat = User::factory()->create()->assignRole($role);
        $this->actingAs($lihat)->get('/les-ekskul/data-siswa')->assertInertia(fn (Assert $page) => $page->component('les-ekskul/siswa/index')
            ->where('bisaUbah', false)
            ->where('siswa.data.0.nama', 'Budi Uji')
            ->where('siswa.data.0.kelas', '2A'));
        $this->actingAs($lihat)->post($this->url, [...$this->data, 'nama' => 'Cici', 'nis' => null, 'nisn' => null])->assertForbidden();
    }
});

test('daftar urut kelas lalu nama dan bisa difilter', function () {
    ($this->buatSiswa)('Zahra', $this->k2A);
    ($this->buatSiswa)('Andi', $this->k2B);
    ($this->buatSiswa)('Bella', $this->k2A);
    ($this->buatSiswa)('Keluar', $this->k3A)->update(['status_sekolah' => StatusSekolah::PindahSekolah]);

    $this->actingAs($this->tu)->get('/les-ekskul/data-siswa')->assertInertia(fn (Assert $page) => $page
        ->where('siswa.data', fn ($d) => collect($d)->pluck('nama')->all() === ['Bella', 'Zahra', 'Andi', 'Keluar']));
    $this->actingAs($this->tu)->get("/les-ekskul/data-siswa?kelas={$this->k2B->id}")->assertInertia(fn (Assert $page) => $page->has('siswa.data', 1));
    $this->actingAs($this->tu)->get('/les-ekskul/data-siswa?status=pindah_sekolah')->assertInertia(fn (Assert $page) => $page->where('siswa.data.0.nama', 'Keluar'));
});

test('pindah kelas berjenjang sama: les ikut pindah; jenjang lain hanya tanpa kegiatan aktif', function () {
    $budi = ($this->buatSiswa)('Budi', $this->k2A);
    $les = ($this->daftar)($budi, ['les_tambahan_id' => $this->les2A->id]);
    $ubah = fn (Siswa $s, Kelas $k) => $this->actingAs($this->tu)->put("{$this->url}/{$s->id}", ['nama' => $s->nama, 'kelas_id' => $k->id]);

    $ubah($budi, $this->k2C)->assertSessionHasErrors('kelas_id'); // 2C tidak punya les
    $ubah($budi, $this->k3A)->assertSessionHasErrors('kelas_id'); // jenjang lain, masih ikut les
    $ubah($budi, $this->k2B)->assertSessionHasNoErrors();

    expect($budi->riwayatKelas()->sole()->kelas_id)->toBe($this->k2B->id)
        ->and($les->fresh()->les_tambahan_id)->toBe($this->les2B->id);

    $cici = ($this->buatSiswa)('Cici', $this->k2A); // tanpa kegiatan: koreksi salah input ke jenjang lain boleh
    $ubah($cici, $this->k3A)->assertSessionHasNoErrors();
    expect($cici->riwayatKelas()->sole()->kelas_id)->toBe($this->k3A->id);
});

test('status anak guru menyesuaikan tagihan yang belum dibayar sama sekali saja', function () {
    $budi = ($this->buatSiswa)('Budi', $this->k2A);
    $basket = ($this->daftar)($budi, ['ekstrakurikuler_id' => $this->basket->id]);
    ($this->tagih)($basket);
    $bulan = fn (string $b) => Tagihan::where('pendaftaran_id', $basket->id)->where('bulan_tagihan', $b.'-01')->sole();

    app(TransaksiService::class)->catat($budi, MetodeTransaksi::Cash, 80000, [$bulan('2026-08')->id], $this->tu); // Agustus lunas
    $k = KonfirmasiTransfer::create(['siswa_id' => $budi->id, 'diajukan_oleh' => $this->tu->id, 'nominal' => 80000, 'tanggal_transfer' => '2026-10-09']);
    $k->tagihan()->attach($bulan('2026-11')->id); // November sedang dikonfirmasi orang tua

    $ubah = fn (?int $guru) => $this->actingAs($this->tu)->put("{$this->url}/{$budi->id}", ['nama' => 'Budi', 'kelas_id' => $this->k2A->id, 'anak_guru_id' => $guru]);
    $ubah($this->guru->id)->assertSessionHasNoErrors();

    expect($bulan('2026-08')->diskon)->toBe(0) // sudah dibayar: tidak diubah
        ->and($bulan('2026-09')->diskon)->toBe(40000)
        ->and($bulan('2026-10')->diskon)->toBe(40000)
        ->and($bulan('2026-11')->diskon)->toBe(0); // sedang dikonfirmasi: tidak diubah

    $ubah(null)->assertSessionHasNoErrors(); // dicabut
    expect($bulan('2026-09')->diskon)->toBe(0)->and($budi->fresh()->anak_guru_id)->toBeNull();
});

test('keluar sekolah memberhentikan semua kegiatan dan mencatat refund bayar di muka; aktifkan kembali', function () {
    $budi = ($this->buatSiswa)('Budi', $this->k2A);
    $basket = ($this->daftar)($budi, ['ekstrakurikuler_id' => $this->basket->id]);
    $les = ($this->daftar)($budi, ['les_tambahan_id' => $this->les2A->id]);
    ($this->tagih)($basket);
    $november = Tagihan::where('pendaftaran_id', $basket->id)->where('bulan_tagihan', '2026-11-01')->sole();
    app(TransaksiService::class)->catat($budi, MetodeTransaksi::Cash, 80000, [$november->id], $this->tu); // bayar di muka November

    $this->actingAs($this->tu)->post("/les-ekskul/data-siswa/{$budi->id}/keluar", ['tanggal' => '2026-11-01', 'alasan' => ''])->assertSessionHasErrors('alasan');
    $this->actingAs($this->tu)->post("/les-ekskul/data-siswa/{$budi->id}/keluar", ['tanggal' => '2026-11-01', 'alasan' => 'Pindah ke luar kota'])
        ->assertSessionHasNoErrors();

    expect($budi->fresh()->status_sekolah)->toBe(StatusSekolah::PindahSekolah)
        ->and($basket->fresh()->status)->toBe(StatusPendaftaran::Berhenti)
        ->and($les->fresh()->status)->toBe(StatusPendaftaran::Berhenti)
        ->and($basket->fresh()->alasan_berhenti)->toBe('Keluar sekolah: Pindah ke luar kota')
        ->and(PenyesuaianDana::sole()->nominal)->toBe(80000); // November sudah dibayar → refund

    $this->actingAs($this->tu)->post("/les-ekskul/data-siswa/{$budi->id}/keluar", ['tanggal' => '2026-11-01', 'alasan' => 'lagi'])->assertSessionHasErrors('siswa');
    $this->actingAs($this->tu)->post("/les-ekskul/data-siswa/{$budi->id}/aktifkan")->assertSessionHasNoErrors();
    expect($budi->fresh()->status_sekolah)->toBe(StatusSekolah::Aktif)
        ->and($basket->fresh()->status)->toBe(StatusPendaftaran::Berhenti); // kegiatan tidak kembali otomatis
});

test('hapus hanya siswa salah input yang belum punya pendaftaran, transaksi, atau klaim orang tua', function () {
    $salah = ($this->buatSiswa)('Salah Ketik', $this->k2A);
    $diklaim = ($this->buatSiswa)('Diklaim', $this->k2A);
    $ortu = User::factory()->create(['email' => null, 'no_hp' => '081234567890'])->assignRole('orang_tua');
    $ortu->anak()->attach($diklaim->id);

    $this->actingAs($this->tu)->delete("/les-ekskul/data-siswa/{$diklaim->id}")->assertSessionHasErrors('siswa');
    $this->actingAs($this->tu)->delete("/les-ekskul/data-siswa/{$salah->id}")->assertSessionHasNoErrors();

    expect(Siswa::find($salah->id))->toBeNull()
        ->and(Siswa::withTrashed()->find($salah->id))->not->toBeNull() // soft delete
        ->and(SiswaKelas::where('siswa_id', $salah->id)->exists())->toBeFalse();
});
