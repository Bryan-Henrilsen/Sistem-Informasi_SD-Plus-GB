<?php

use App\Domains\LesEkskul\Enums\MetodeTransaksi;
use App\Domains\LesEkskul\Models\Ekstrakurikuler;
use App\Domains\LesEkskul\Models\LesTambahan;
use App\Domains\LesEkskul\Models\Pendaftaran;
use App\Domains\LesEkskul\Models\PeriodeLaporan;
use App\Domains\LesEkskul\Models\Tagihan;
use App\Domains\LesEkskul\Services\RekapIuranService;
use App\Domains\LesEkskul\Services\TagihanService;
use App\Models\Kelas;
use App\Models\Siswa;
use App\Models\SiswaKelas;
use App\Models\TahunAjaran;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Inertia\Testing\AssertableInertia as Assert;

// Rekap Iuran tahunan pengganti buku rekap TU (FR-13b, Ringkasan v1.11 #40)
beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->travelTo('2026-11-20 09:00');

    $this->tu = User::factory()->create(['name' => 'Serafina Fuijun, A.Md.'])->assignRole('tu');

    $this->ta = TahunAjaran::create(['nama' => '2026/2027', 'tanggal_mulai' => '2026-07-13', 'tanggal_selesai' => '2027-06-30', 'status_aktif' => true]);
    $this->ta->semester()->createMany([
        ['urutan' => 1, 'nama' => 'Semester 1', 'tanggal_mulai' => '2026-07-13', 'tanggal_selesai' => '2026-12-31'],
        ['urutan' => 2, 'nama' => 'Semester 2', 'tanggal_mulai' => '2027-01-01', 'tanggal_selesai' => '2027-06-30'],
    ]);
    PeriodeLaporan::create(['bulan' => '2026-11-01', 'tanggal_mulai' => '2026-10-23', 'tanggal_selesai' => '2026-11-22']);

    $kelas = fn (int $jenjang, string $nama) => Kelas::create(['tahun_ajaran_id' => $this->ta->id, 'jenjang' => $jenjang, 'nama_kelas' => $nama]);
    $this->kelas1A = $kelas(1, '1A');
    $this->kelas2A = $kelas(2, '2A');
    $this->kelas2B = $kelas(2, '2B');

    $this->basket = Ekstrakurikuler::create(['tahun_ajaran_id' => $this->ta->id, 'nama' => 'Basket', 'biaya' => 80000]);
    $english = Ekstrakurikuler::create(['tahun_ajaran_id' => $this->ta->id, 'nama' => 'English Club', 'biaya' => 100000]);
    $flyer = Ekstrakurikuler::create(['tahun_ajaran_id' => $this->ta->id, 'parent_ekstrakurikuler_id' => $english->id, 'nama' => 'English Club Flyer', 'biaya' => 100000]);
    $les2A = LesTambahan::create(['kelas_id' => $this->kelas2A->id, 'biaya' => 50000]);

    $siswa = function (string $nama, Kelas $kelas) {
        $s = Siswa::create(['nama' => $nama]);
        SiswaKelas::create(['siswa_id' => $s->id, 'kelas_id' => $kelas->id]);

        return $s;
    };
    $this->citra = $siswa('Citra', $this->kelas2B);
    $this->budi = $siswa('Budi', $this->kelas2A);
    $this->andi = $siswa('Andi', $this->kelas2A);
    $this->dewi = $siswa('Dewi', $this->kelas1A);

    $daftar = fn (Siswa $s, array $kegiatan) => Pendaftaran::create(['siswa_id' => $s->id, 'tanggal_daftar' => '2026-07-15', ...$kegiatan]);
    $this->basketCitra = $daftar($this->citra, ['ekstrakurikuler_id' => $this->basket->id]);
    $this->basketBudi = $daftar($this->budi, ['ekstrakurikuler_id' => $this->basket->id]);
    $this->basketAndi = $daftar($this->andi, ['ekstrakurikuler_id' => $this->basket->id]);
    $daftar($this->andi, ['les_tambahan_id' => $les2A->id]);
    $daftar($this->dewi, ['ekstrakurikuler_id' => $flyer->id]);

    foreach (Pendaftaran::all() as $p) {
        foreach (['2026-08-01', '2026-09-01', '2026-10-01', '2026-11-01'] as $bulan) {
            app(TagihanService::class)->buatUntukBulan($p, $bulan);
        }
    }

    $this->tagihan = fn (Pendaftaran $p, string $bulan) => Tagihan::where('pendaftaran_id', $p->id)->where('bulan_tagihan', $bulan.'-01')->sole();
    $this->bayar = fn (Siswa $siswa, string $metode, int $nominal, array $tagihan) => $this->actingAs($this->tu)->post('/les-ekskul/transaksi', [
        'siswa_id' => $siswa->id, 'metode' => $metode, 'nominal' => $nominal, 'tanggal' => '2026-11-20',
        'tagihan_ids' => array_map(fn (Tagihan $t) => $t->id, $tagihan),
    ]);
});

test('baris tersusun per buku: Les Tambahan dulu, lalu ekskul abjad; di dalam buku urut kelas lalu abjad', function () {
    $this->actingAs($this->tu)->get('/les-ekskul/tagihan')
        ->assertInertia(fn (Assert $page) => $page->component('les-ekskul/tagihan/index')
            ->where('buku.0.kunci', 'les')
            ->where('buku.1.nama', 'Basket')
            ->where('buku.2.nama', 'English Club') // kelas paralel digabung di bawah induknya
            ->where('bulan', ['2026-08', '2026-09', '2026-10', '2026-11', '2027-01', '2027-02', '2027-03', '2027-04', '2027-05'])
            ->where('baris.0.nama_buku', 'Les Tambahan')
            ->where('baris.1.siswa.nama', 'Andi') // Basket 2A
            ->where('baris.2.siswa.nama', 'Budi') // Basket 2A
            ->where('baris.3.siswa.nama', 'Citra') // Basket 2B
            ->where('baris.3.kelas', '2B')
            ->where('baris.4.kegiatan', 'English Club Flyer')
            ->where('bisaCatat', true));
});

test('kotak berisi tanggal, metode, nominal, dan paraf; warnanya mengikuti status', function () {
    ($this->bayar)($this->andi, 'cash', 80000, [($this->tagihan)($this->basketAndi, '2026-08')]);
    ($this->bayar)($this->budi, 'transfer', 80000, [($this->tagihan)($this->basketBudi, '2026-08')]);
    ($this->bayar)($this->citra, 'cash', 30000, [($this->tagihan)($this->basketCitra, '2026-08')]);

    $this->actingAs($this->tu)->get('/les-ekskul/tagihan?buku=ekskul-'.$this->basket->id)
        ->assertInertia(fn (Assert $page) => $page
            ->has('baris', 3)
            ->where('baris.0.bulan.2026-08.status', 'lunas')
            ->where('baris.0.bulan.2026-08.bayar.0.tanggal', '2026-11-20')
            ->where('baris.0.bulan.2026-08.bayar.0.metode', 'cash')
            ->where('baris.0.bulan.2026-08.bayar.0.nominal', 80000)
            ->where('baris.0.bulan.2026-08.bayar.0.paraf', 'SF')
            ->where('baris.0.bulan.2026-09.status', 'tunggakan') // bulan sudah lewat, belum dibayar
            ->where('baris.0.bulan.2026-11.status', 'belum') // bulan berjalan
            ->where('baris.1.bulan.2026-08.status', 'menunggu') // transfer belum diverifikasi TU
            ->where('baris.1.menunggu', true)
            ->where('baris.2.bulan.2026-08.status', 'sebagian')
            ->where('baris.2.bulan.2026-08.sisa', 50000)
            ->where('baris.2.tunggakan', 50000 + 80000 + 80000) // sisa Agustus + September + Oktober
            ->where('kartu.menunggu_verifikasi', 1)
            ->where('ringkasan.1.bulan.2026-08.lunas', 2)
            ->where('ringkasan.1.bulan.2026-08.total', 3)
            ->where('ringkasan.1.bulan.2026-08.terbayar', 190000));
});

test('filter kelas, status, dan nama', function () {
    ($this->bayar)($this->budi, 'transfer', 80000, [($this->tagihan)($this->basketBudi, '2026-08')]);
    $get = fn (string $query) => $this->actingAs($this->tu)->get('/les-ekskul/tagihan?'.$query);

    $get('kelas='.$this->kelas2B->id)->assertInertia(fn (Assert $page) => $page->has('baris', 1)->where('baris.0.siswa.nama', 'Citra'));
    $get('status=menunggu')->assertInertia(fn (Assert $page) => $page->has('baris', 1)->where('baris.0.siswa.nama', 'Budi'));
    $get('nama=dew')->assertInertia(fn (Assert $page) => $page->has('baris', 1)->where('baris.0.siswa.nama', 'Dewi'));
    $get('status=tunggakan')->assertInertia(fn (Assert $page) => $page->has('baris', 5)); // semuanya belum bayar September
});

test('Bendahara dan Kepala Sekolah bisa melihat tanpa tombol catat', function () {
    foreach (['bendahara', 'kepala_sekolah'] as $role) {
        $this->actingAs(User::factory()->create()->assignRole($role))->get('/les-ekskul/tagihan')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('baris', 5)->where('bisaCatat', false));
    }
});

test('catat pembayaran dari kotak rekap: tagihan tercentang, lalu kembali ke rekap dengan kotak disorot', function () {
    $agustus = ($this->tagihan)($this->basketAndi, '2026-08');
    $kembali = '/les-ekskul/tagihan?buku=ekskul-'.$this->basket->id;

    $this->actingAs($this->tu)->get("/les-ekskul/transaksi/catat?siswa={$this->andi->id}&tagihan={$agustus->id}&kembali=".urlencode($kembali))
        ->assertInertia(fn (Assert $page) => $page->where('tagihanDipilih', $agustus->id)->where('kembali', $kembali));

    $this->actingAs($this->tu)->post('/les-ekskul/transaksi', [
        'siswa_id' => $this->andi->id, 'metode' => 'cash', 'nominal' => 80000, 'tanggal' => '2026-11-20',
        'tagihan_ids' => [$agustus->id], 'kembali' => $kembali,
    ])->assertRedirect($kembali)->assertInertiaFlash('disorot', [$agustus->id]);

    // Alamat kembali di luar Rekap Iuran ditolak (mencegah pengalihan ke situs lain)
    $this->actingAs($this->tu)->post('/les-ekskul/transaksi', [
        'siswa_id' => $this->andi->id, 'metode' => 'cash', 'nominal' => 80000, 'tanggal' => '2026-11-20',
        'tagihan_ids' => [($this->tagihan)($this->basketAndi, '2026-09')->id], 'kembali' => 'https://contoh.com',
    ])->assertSessionHasErrors('kembali');
});

test('tahun ajaran lalu tetap bisa dibuka', function () {
    $lalu = TahunAjaran::create(['nama' => '2025/2026', 'tanggal_mulai' => '2025-07-14', 'tanggal_selesai' => '2026-06-30', 'status_aktif' => false]);
    $futsal = Ekstrakurikuler::create(['tahun_ajaran_id' => $lalu->id, 'nama' => 'Futsal', 'biaya' => 70000]);
    $p = Pendaftaran::create(['siswa_id' => $this->andi->id, 'ekstrakurikuler_id' => $futsal->id, 'tanggal_daftar' => '2025-07-20']);
    Tagihan::create(['pendaftaran_id' => $p->id, 'bulan_tagihan' => '2025-08-01', 'nominal' => 70000]);

    $this->actingAs($this->tu)->get("/les-ekskul/tagihan?tahun={$lalu->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('tahunAjaran.nama', '2025/2026')
            ->has('baris', 1)
            ->where('baris.0.nama_buku', 'Futsal')
            ->where('baris.0.kelas', null) // siswa belum punya kelas tercatat di tahun itu
            ->where('bulan.0', '2025-08'));
});

test('paraf dari inisial nama tanpa gelar; pembayaran online dicatat sistem', function () {
    $angela = new User(['name' => 'Angela Genoveva J. G., S.Pd.']);

    expect(RekapIuranService::paraf($angela, MetodeTransaksi::Transfer))->toBe('AG')
        ->and(RekapIuranService::paraf($this->tu, MetodeTransaksi::Cash))->toBe('SF')
        ->and(RekapIuranService::paraf(null, MetodeTransaksi::Online))->toBe('sistem');
});
