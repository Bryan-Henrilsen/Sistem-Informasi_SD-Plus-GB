<?php

use App\Domains\LesEkskul\Enums\JenisPenyesuaian;
use App\Domains\LesEkskul\Enums\MetodeTransaksi;
use App\Domains\LesEkskul\Enums\StatusPenyesuaian;
use App\Domains\LesEkskul\Enums\StatusPeriodeLaporan;
use App\Domains\LesEkskul\Models\Ekstrakurikuler;
use App\Domains\LesEkskul\Models\KegiatanLibur;
use App\Domains\LesEkskul\Models\LesTambahan;
use App\Domains\LesEkskul\Models\Pendaftaran;
use App\Domains\LesEkskul\Models\PenyesuaianDana;
use App\Domains\LesEkskul\Models\PeriodeLaporan;
use App\Domains\LesEkskul\Models\Tagihan;
use App\Domains\LesEkskul\Services\KegiatanLiburService;
use App\Domains\LesEkskul\Services\TagihanOrangTuaService;
use App\Domains\LesEkskul\Services\TagihanService;
use App\Domains\LesEkskul\Services\TransaksiService;
use App\Models\Kelas;
use App\Models\Siswa;
use App\Models\SiswaKelas;
use App\Models\TahunAjaran;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Inertia\Testing\AssertableInertia as Assert;

// Kegiatan tidak berlangsung sebulan penuh (FR-23, Ringkasan v1.11 #48)
beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->travelTo('2026-10-15 09:00');

    $this->tu = User::factory()->create()->assignRole('tu');

    $ta = TahunAjaran::create(['nama' => '2026/2027', 'tanggal_mulai' => '2026-07-13', 'tanggal_selesai' => '2027-06-30', 'status_aktif' => true]);
    foreach (['2026-08', '2026-09', '2026-10', '2026-11'] as $bulan) {
        $awal = CarbonImmutable::parse("{$bulan}-01");
        PeriodeLaporan::create(['bulan' => $awal, 'tanggal_mulai' => $awal->subMonth()->day(23), 'tanggal_selesai' => $awal->day(22)]);
    }
    // Agustus & September sudah tutup buku
    PeriodeLaporan::where('bulan', '<=', '2026-09-01')->update(['status' => StatusPeriodeLaporan::Ditutup]);

    $kelas = Kelas::create(['tahun_ajaran_id' => $ta->id, 'jenjang' => 3, 'nama_kelas' => '3A']);
    $this->andi = Siswa::create(['nama' => 'Andi']);
    $this->budi = Siswa::create(['nama' => 'Budi']);
    foreach ([$this->andi, $this->budi] as $s) {
        SiswaKelas::create(['siswa_id' => $s->id, 'kelas_id' => $kelas->id]);
    }

    $this->les = LesTambahan::create(['kelas_id' => $kelas->id, 'biaya' => 150000]);
    $this->english = Ekstrakurikuler::create(['tahun_ajaran_id' => $ta->id, 'nama' => 'English Club', 'biaya' => 100000]);
    $this->flyer = Ekstrakurikuler::create(['tahun_ajaran_id' => $ta->id, 'parent_ekstrakurikuler_id' => $this->english->id, 'nama' => 'English Club Flyer', 'biaya' => 100000]);

    // Pendaftaran sejak Juli, tagihan Agustus–November sudah dibuat
    $this->ikut = function (Siswa $siswa, LesTambahan|Ekstrakurikuler $kegiatan, string $sampai = '2026-11-01'): Pendaftaran {
        $p = Pendaftaran::create([
            'siswa_id' => $siswa->id,
            'les_tambahan_id' => $kegiatan instanceof LesTambahan ? $kegiatan->id : null,
            'ekstrakurikuler_id' => $kegiatan instanceof Ekstrakurikuler ? $kegiatan->id : null,
            'tanggal_daftar' => '2026-07-20',
        ]);
        app(TagihanService::class)->buatSampai($p, $sampai);

        return $p;
    };
    $this->bayar = function (Pendaftaran $p, string $bulan, MetodeTransaksi $metode = MetodeTransaksi::Cash): void {
        $t = $p->tagihan()->where('bulan_tagihan', "{$bulan}-01")->sole();
        app(TransaksiService::class)->catat($p->siswa, $metode, $t->sisa(), [$t->id], $this->tu);
    };
    $this->status = fn (Pendaftaran $p, string $bulan) => ($t = Tagihan::where('pendaftaran_id', $p->id)->where('bulan_tagihan', "{$bulan}-01")->first())
        ? $t->status->value.':'.$t->sisa()
        : null;
    $this->tandai = fn (array $data) => $this->actingAs($this->tu)->post('/les-ekskul/kegiatan-libur', $data + ['alasan' => 'Pengajar berhalangan']);
});

test('les tidak berlangsung November: tagihan dibatalkan dan uangnya membayar Januari (Desember dilewati)', function () {
    $les = ($this->ikut)($this->andi, $this->les);
    ($this->bayar)($les, '2026-11'); // bayar di muka

    ($this->tandai)(['kegiatan' => "les-{$this->les->id}", 'bulan' => '2026-11'])->assertSessionHasNoErrors();

    expect(($this->status)($les, '2026-11'))->toBe('dibatalkan:150000')
        ->and(($this->status)($les, '2026-12'))->toBeNull()
        ->and(($this->status)($les, '2027-01'))->toBe('lunas:0');

    $libur = KegiatanLibur::sole();
    expect($libur->jumlah_tagihan)->toBe(1)
        ->and($libur->nominal_dialihkan)->toBe(150000)
        ->and($libur->nominal_refund)->toBe(0);

    $penyesuaian = PenyesuaianDana::sole();
    expect($penyesuaian->jenis_penyesuaian)->toBe(JenisPenyesuaian::KegiatanTidakBerlangsung)
        ->and($penyesuaian->status)->toBe(StatusPenyesuaian::Selesai)
        ->and($penyesuaian->nominal)->toBe(150000);

    // Uang sudah berpindah: penandaan tidak bisa dibatalkan
    $this->actingAs($this->tu)->delete("/les-ekskul/kegiatan-libur/{$libur->id}")->assertSessionHasErrors('libur');
    expect(KegiatanLibur::count())->toBe(1);
});

test('pratinjau menghitung dampak tanpa menyimpan apa pun', function () {
    $les = ($this->ikut)($this->andi, $this->les);
    ($this->bayar)($les, '2026-11');
    ($this->ikut)($this->budi, $this->les);

    $hasil = app(KegiatanLiburService::class)->pratinjau(['kegiatan' => "les-{$this->les->id}", 'bulan' => '2026-11'], $this->tu);

    expect($hasil['jumlah_tagihan'])->toBe(2)
        ->and($hasil['dialihkan'])->toBe(150000)
        ->and($hasil['refund'])->toBe(0)
        ->and(collect($hasil['rincian'])->pluck('siswa')->all())->toBe(['Andi', 'Budi'])
        ->and(KegiatanLibur::count())->toBe(0)
        ->and(PenyesuaianDana::count())->toBe(0)
        ->and(($this->status)($les, '2026-11'))->toBe('lunas:0')
        ->and(($this->status)($les, '2027-01'))->toBeNull();

    $this->actingAs($this->tu)->post('/les-ekskul/kegiatan-libur/pratinjau', ['kegiatan' => "les-{$this->les->id}", 'bulan' => '2026-11'])
        ->assertSessionHasNoErrors();
    expect(KegiatanLibur::count())->toBe(0);
});

test('ekskul induk berlaku untuk kelas paralelnya; tagihan baru bulan itu tidak dibuat', function () {
    $andiFlyer = ($this->ikut)($this->andi, $this->flyer);
    $budiInduk = ($this->ikut)($this->budi, $this->english);

    ($this->tandai)(['kegiatan' => "ekskul-{$this->english->id}", 'bulan' => '2026-11'])->assertSessionHasNoErrors();

    expect(($this->status)($andiFlyer, '2026-11'))->toBe('dibatalkan:100000')
        ->and(($this->status)($budiInduk, '2026-11'))->toBe('dibatalkan:100000')
        ->and(($this->status)($budiInduk, '2026-10'))->toBe('belum:100000');

    // Kelas paralel bulan yang sama sudah tercakup penandaan induk
    ($this->tandai)(['kegiatan' => "ekskul-{$this->flyer->id}", 'bulan' => '2026-11'])->assertSessionHasErrors('kegiatan');

    // Pendaftar baru kelas paralel: November tidak ditagih
    $baru = Siswa::create(['nama' => 'Citra']);
    $citra = Pendaftaran::create(['siswa_id' => $baru->id, 'ekstrakurikuler_id' => $this->flyer->id, 'tanggal_daftar' => '2026-10-20']);
    app(TagihanService::class)->buatSetelahDaftar($citra);
    expect($citra->tagihan()->pluck('bulan_tagihan')->map->format('Y-m')->all())->toBe(['2026-10']);
});

test('bulan yang sudah tutup buku atau tidak ditagih ditolak; alasan wajib', function () {
    ($this->ikut)($this->andi, $this->les);

    ($this->tandai)(['kegiatan' => "les-{$this->les->id}", 'bulan' => '2026-09'])->assertSessionHasErrors('bulan');
    ($this->tandai)(['kegiatan' => "les-{$this->les->id}", 'bulan' => '2026-12'])->assertSessionHasErrors('bulan');
    $this->actingAs($this->tu)->post('/les-ekskul/kegiatan-libur', ['kegiatan' => "les-{$this->les->id}", 'bulan' => '2026-11'])
        ->assertSessionHasErrors('alasan');

    expect(KegiatanLibur::count())->toBe(0);
});

test('transfer yang belum diverifikasi menahan penandaan', function () {
    $les = ($this->ikut)($this->andi, $this->les);
    ($this->bayar)($les, '2026-11', MetodeTransaksi::Transfer);

    ($this->tandai)(['kegiatan' => "les-{$this->les->id}", 'bulan' => '2026-11'])->assertSessionHasErrors('bulan');
    expect(KegiatanLibur::count())->toBe(0)->and(($this->status)($les, '2026-11'))->toBe('lunas:0');
});

test('tanpa bulan tujuan sampai akhir tahun ajaran: uangnya direfund dan muncul di halaman Refund', function () {
    $les = ($this->ikut)($this->andi, $this->les, '2027-05-01');
    ($this->bayar)($les, '2027-05'); // Mei bulan terakhir yang ditagih

    ($this->tandai)(['kegiatan' => "les-{$this->les->id}", 'bulan' => '2027-05'])->assertSessionHasNoErrors();

    $refund = PenyesuaianDana::sole();
    expect($refund->status)->toBe(StatusPenyesuaian::Proses)
        ->and($refund->nominal)->toBe(150000)
        ->and($refund->adalahRefund())->toBeTrue()
        ->and(KegiatanLibur::sole()->nominal_refund)->toBe(150000);

    $this->actingAs($this->tu)->get('/les-ekskul/refund')
        ->assertInertia(fn (Assert $page) => $page->where('totalBelum', 150000)->where('refund.data.0.jenis', 'kegiatan_tidak_berlangsung'));

    $this->actingAs($this->tu)->post("/les-ekskul/refund/{$refund->id}/dikembalikan")->assertSessionHasNoErrors();
    expect($refund->fresh()->status)->toBe(StatusPenyesuaian::Berhasil);
});

test('batalkan penandaan tanpa uang: tagihan ditagih kembali', function () {
    $les = ($this->ikut)($this->andi, $this->les);
    ($this->tandai)(['kegiatan' => "les-{$this->les->id}", 'bulan' => '2026-11'])->assertSessionHasNoErrors();
    expect(($this->status)($les, '2026-11'))->toBe('dibatalkan:150000');

    $this->actingAs($this->tu)->delete('/les-ekskul/kegiatan-libur/'.KegiatanLibur::sole()->id)->assertSessionHasNoErrors();

    expect(KegiatanLibur::count())->toBe(0)
        ->and(($this->status)($les, '2026-11'))->toBe('belum:150000');
});

test('Rekap Iuran menandai kotak libur dan orang tua melihat catatannya', function () {
    $les = ($this->ikut)($this->andi, $this->les);
    ($this->tandai)(['kegiatan' => "les-{$this->les->id}", 'bulan' => '2026-11'])->assertSessionHasNoErrors();

    $this->actingAs($this->tu)->get('/les-ekskul/tagihan')
        ->assertInertia(fn (Assert $page) => $page
            ->where('baris.0.bulan.2026-11.status', 'libur')
            ->where('baris.0.libur.2026-11', 'Pengajar berhalangan')
            ->where('baris.0.tunggakan', 300000)); // Agustus & September belum dibayar; November tidak dihitung

    expect(app(TagihanOrangTuaService::class)->kegiatanLibur($this->andi)->all())->toBe([
        ['id' => KegiatanLibur::sole()->id, 'kegiatan' => 'Les Tambahan 3A', 'bulan' => '2026-11-01', 'alasan' => 'Pengajar berhalangan'],
    ])->and(app(TagihanOrangTuaService::class)->kegiatanLibur($this->budi))->toBeEmpty();
});

test('Bendahara hanya melihat; TU yang menandai', function () {
    $bendahara = User::factory()->create()->assignRole('bendahara');

    $this->actingAs($bendahara)->get('/les-ekskul/kegiatan-libur')
        ->assertInertia(fn (Assert $page) => $page->component('les-ekskul/kegiatan-libur')->where('bisaUbah', false));
    $this->actingAs($bendahara)->post('/les-ekskul/kegiatan-libur', ['kegiatan' => "les-{$this->les->id}", 'bulan' => '2026-11', 'alasan' => 'x'])
        ->assertForbidden();

    $this->actingAs($this->tu)->get('/les-ekskul/kegiatan-libur')
        ->assertInertia(fn (Assert $page) => $page
            ->where('bisaUbah', true)
            ->where('pilihanBulan', ['2026-10', '2026-11', '2027-01', '2027-02', '2027-03', '2027-04', '2027-05'])
            ->where('pilihanKegiatan.1.nama', 'English Club (semua kelas)')
            ->where('pilihanKegiatan.2.nama', 'English Club Flyer'));
});
