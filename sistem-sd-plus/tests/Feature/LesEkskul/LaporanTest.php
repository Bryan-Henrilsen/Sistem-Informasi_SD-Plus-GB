<?php

use App\Domains\LesEkskul\Enums\MetodeTransaksi;
use App\Domains\LesEkskul\Enums\StatusLaporanHarian;
use App\Domains\LesEkskul\Enums\StatusPeriodeLaporan;
use App\Domains\LesEkskul\Models\Ekstrakurikuler;
use App\Domains\LesEkskul\Models\LaporanHarian;
use App\Domains\LesEkskul\Models\Pendaftaran;
use App\Domains\LesEkskul\Models\PeriodeLaporan;
use App\Domains\LesEkskul\Models\Tagihan;
use App\Domains\LesEkskul\Models\Transaksi;
use App\Domains\LesEkskul\Services\TagihanService;
use App\Domains\LesEkskul\Services\TransaksiService;
use App\Models\Kelas;
use App\Models\Siswa;
use App\Models\SiswaKelas;
use App\Models\TahunAjaran;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->travelTo('2026-08-10 09:00'); // Senin

    $this->tu = User::factory()->create()->assignRole('tu');
    $this->bendahara = User::factory()->create()->assignRole('bendahara');

    $ta = TahunAjaran::create(['nama' => '2026/2027', 'tanggal_mulai' => '2026-07-13', 'tanggal_selesai' => '2027-06-30', 'status_aktif' => true]);
    $ta->semester()->createMany([
        ['urutan' => 1, 'nama' => 'Semester 1', 'tanggal_mulai' => '2026-07-13', 'tanggal_selesai' => '2026-12-31'],
        ['urutan' => 2, 'nama' => 'Semester 2', 'tanggal_mulai' => '2027-01-01', 'tanggal_selesai' => '2027-06-30'],
    ]);

    $this->periode = collect(['2026-08', '2026-09', '2026-10', '2026-11'])->mapWithKeys(function (string $bulan) {
        $awal = CarbonImmutable::parse("{$bulan}-01");

        return [$bulan => PeriodeLaporan::create(['bulan' => $awal, 'tanggal_mulai' => $awal->subMonth()->day(23), 'tanggal_selesai' => $awal->day(22)])];
    });

    $kelas = Kelas::create(['tahun_ajaran_id' => $ta->id, 'jenjang' => 3, 'nama_kelas' => '3A']);
    $this->siswa = Siswa::create(['nama' => 'Budi Uji']);
    SiswaKelas::create(['siswa_id' => $this->siswa->id, 'kelas_id' => $kelas->id]);

    $basket = Ekstrakurikuler::create(['tahun_ajaran_id' => $ta->id, 'nama' => 'Basket', 'biaya' => 80000]);
    $this->pendaftaran = Pendaftaran::create(['siswa_id' => $this->siswa->id, 'ekstrakurikuler_id' => $basket->id, 'tanggal_daftar' => '2026-07-20']);
    app(TagihanService::class)->buatSampai($this->pendaftaran, '2026-11-01');
});

function catatBayar(array $bulan, MetodeTransaksi $metode = MetodeTransaksi::Cash): Transaksi
{
    $tagihan = test()->pendaftaran->tagihan()->whereIn('bulan_tagihan', array_map(fn ($b) => "{$b}-01", $bulan))->get();

    return app(TransaksiService::class)->catat(test()->siswa, $metode, $tagihan->sum(fn (Tagihan $t) => $t->sisa()), $tagihan->pluck('id')->all(), test()->tu);
}

function aksiLaporan(User $oleh, string $url, array $data = [])
{
    return test()->actingAs($oleh)->post("/les-ekskul/{$url}", $data);
}

// Ajukan semua laporan harian periode itu (TU), setujui (Bendahara), lalu ajukan laporan bulanannya (TU)
function siapkanTutupBuku(PeriodeLaporan $periode): void
{
    foreach ($periode->laporanHarian as $laporan) {
        aksiLaporan(test()->tu, "laporan-harian/{$laporan->id}/ajukan");
        aksiLaporan(test()->bendahara, "laporan-harian/{$laporan->id}/setujui");
    }

    aksiLaporan(test()->tu, "periode/{$periode->id}/ajukan")->assertSessionHasNoErrors();
}

test('TU mengajukan dan menarik kembali; Bendahara menolak lalu menyetujui laporan harian', function () {
    catatBayar(['2026-08']);
    $laporan = LaporanHarian::sole();
    expect($laporan->status)->toBe(StatusLaporanHarian::Draft);

    aksiLaporan($this->bendahara, "laporan-harian/{$laporan->id}/ajukan")->assertForbidden();
    aksiLaporan($this->tu, "laporan-harian/{$laporan->id}/ajukan")->assertSessionHasNoErrors();
    aksiLaporan($this->tu, "laporan-harian/{$laporan->id}/tarik");
    expect($laporan->fresh())->status->toBe(StatusLaporanHarian::Draft)->diajukan_oleh->toBeNull();

    aksiLaporan($this->tu, "laporan-harian/{$laporan->id}/ajukan");
    aksiLaporan($this->tu, "laporan-harian/{$laporan->id}/setujui")->assertForbidden();
    aksiLaporan($this->bendahara, "laporan-harian/{$laporan->id}/tolak", ['alasan' => ''])->assertSessionHasErrors('alasan');
    aksiLaporan($this->bendahara, "laporan-harian/{$laporan->id}/tolak", ['alasan' => 'Uang di laci kurang']);
    expect($laporan->fresh())->status->toBe(StatusLaporanHarian::Ditolak)->alasan_ditolak->toBe('Uang di laci kurang');

    aksiLaporan($this->tu, "laporan-harian/{$laporan->id}/ajukan"); // diperbaiki lalu diajukan ulang
    aksiLaporan($this->bendahara, "laporan-harian/{$laporan->id}/setujui")->assertSessionHasNoErrors();
    expect($laporan->fresh())->status->toBe(StatusLaporanHarian::Disetujui)->disetujui_oleh->toBe($this->bendahara->id);
});

test('laporan bulanan baru bisa diajukan setelah periode berakhir dan semua laporan harian disetujui', function () {
    catatBayar(['2026-08']);
    $agustus = $this->periode['2026-08'];
    $laporan = LaporanHarian::sole();

    aksiLaporan($this->tu, "periode/{$agustus->id}/ajukan")->assertSessionHasErrors('periode'); // periode belum berakhir

    $this->travelTo('2026-08-25 09:00');
    aksiLaporan($this->tu, "periode/{$agustus->id}/ajukan")->assertSessionHasErrors('periode'); // laporan harian belum disetujui

    aksiLaporan($this->tu, "laporan-harian/{$laporan->id}/ajukan");
    aksiLaporan($this->bendahara, "laporan-harian/{$laporan->id}/setujui");
    aksiLaporan($this->tu, "periode/{$agustus->id}/ajukan")->assertSessionHasNoErrors();

    expect($agustus->fresh())->status->toBe(StatusPeriodeLaporan::Diajukan)->diajukan_oleh->toBe($this->tu->id);
});

test('tutup buku terkunci selama ada transfer belum selesai, dan harus berurutan', function () {
    $transfer = catatBayar(['2026-08'], MetodeTransaksi::Transfer); // pending
    [$agustus, $september] = [$this->periode['2026-08'], $this->periode['2026-09']];

    $this->travelTo('2026-08-25 09:00');
    siapkanTutupBuku($agustus); // laporan harian boleh disetujui walau transfernya belum selesai (Catatan v1.4)
    aksiLaporan($this->bendahara, "periode/{$agustus->id}/tutup-buku")->assertSessionHasErrors('periode');

    $this->travelTo('2026-09-25 09:00');
    siapkanTutupBuku($september); // September tanpa transaksi
    aksiLaporan($this->bendahara, "periode/{$september->id}/tutup-buku")
        ->assertSessionHasErrors(['periode' => 'Periode Agustus 2026 belum ditutup; tutup buku harus berurutan.']);

    app(TransaksiService::class)->verifikasi($transfer, $this->tu);
    app(TransaksiService::class)->setujui($transfer->fresh(), $this->bendahara);

    aksiLaporan($this->tu, "periode/{$agustus->id}/tutup-buku")->assertForbidden(); // hanya Bendahara
    aksiLaporan($this->bendahara, "periode/{$agustus->id}/tutup-buku")->assertSessionHasNoErrors();
    aksiLaporan($this->bendahara, "periode/{$september->id}/tutup-buku")->assertSessionHasNoErrors();

    expect($agustus->fresh()->status)->toBe(StatusPeriodeLaporan::Ditutup)
        ->and($september->fresh()->ditutup_oleh)->toBe($this->bendahara->id);
});

test('Bendahara mengembalikan laporan bulanan ke TU dengan alasan', function () {
    $agustus = $this->periode['2026-08'];
    $this->travelTo('2026-08-25 09:00');
    siapkanTutupBuku($agustus);

    aksiLaporan($this->bendahara, "periode/{$agustus->id}/kembalikan", ['alasan' => ''])->assertSessionHasErrors('alasan');
    aksiLaporan($this->bendahara, "periode/{$agustus->id}/kembalikan", ['alasan' => 'Cek ulang transfer tanggal 10']);

    expect($agustus->fresh())
        ->status->toBe(StatusPeriodeLaporan::Terbuka)
        ->diajukan_oleh->toBeNull()
        ->alasan_dikembalikan->toBe('Cek ulang transfer tanggal 10');

    $this->actingAs($this->tu)->get("/les-ekskul/periode/{$agustus->id}")
        ->assertInertia(fn (Assert $page) => $page->component('les-ekskul/periode/show')
            ->where('periode.alasan_dikembalikan', 'Cek ulang transfer tanggal 10')
            ->where('hambatanAjukan', []));
});

test('tutup buku mengakui tunggakan dan bulan berjalan; bayar di muka menunggu tutup buku bulannya', function () {
    $this->travelTo('2026-08-25 09:00');
    siapkanTutupBuku($this->periode['2026-08']);
    aksiLaporan($this->bendahara, "periode/{$this->periode['2026-08']->id}/tutup-buku");

    $this->travelTo('2026-09-10 09:00');
    catatBayar(['2026-08', '2026-09', '2026-10']); // tunggakan Agustus + September + bayar di muka Oktober

    $this->travelTo('2026-09-25 09:00');
    siapkanTutupBuku($this->periode['2026-09']->fresh());
    aksiLaporan($this->bendahara, "periode/{$this->periode['2026-09']->id}/tutup-buku");

    $diakui = fn () => DB::table('alokasi_transaksi')->join('tagihan', 'tagihan.id', '=', 'alokasi_transaksi.tagihan_id')
        ->orderBy('bulan_tagihan')->pluck('diakui_periode_id', 'bulan_tagihan')->all();

    expect($diakui())->toBe([
        '2026-08-01' => $this->periode['2026-09']->id,
        '2026-09-01' => $this->periode['2026-09']->id,
        '2026-10-01' => null,
    ]);

    $this->actingAs($this->bendahara)->get("/les-ekskul/periode/{$this->periode['2026-09']->id}")
        ->assertInertia(fn (Assert $page) => $page->where('ringkasan.diakui_honor', 160000));

    $this->travelTo('2026-10-25 09:00');
    siapkanTutupBuku($this->periode['2026-10']);
    aksiLaporan($this->bendahara, "periode/{$this->periode['2026-10']->id}/tutup-buku");

    expect($diakui()['2026-10-01'])->toBe($this->periode['2026-10']->id);
});

test('berhenti tidak bisa membatalkan tagihan yang sudah diakui saat tutup buku', function () {
    $this->travelTo('2026-08-12 09:00');
    catatBayar(['2026-08', '2026-09']);
    $this->travelTo('2026-08-25 09:00');
    siapkanTutupBuku($this->periode['2026-08']->fresh());
    aksiLaporan($this->bendahara, "periode/{$this->periode['2026-08']->id}/tutup-buku");

    aksiLaporan($this->tu, "pendaftaran/{$this->pendaftaran->id}/berhenti", ['tanggal' => '2026-08-01'])
        ->assertSessionHasErrors('tanggal'); // Agustus sudah diakui

    aksiLaporan($this->tu, "pendaftaran/{$this->pendaftaran->id}/berhenti", ['tanggal' => '2026-09-01'])
        ->assertSessionHasNoErrors(); // September dibayar di muka, belum diakui -> direfund
});

test('Kepala Sekolah dapat melihat laporan harian dan bulanan tanpa mengubahnya', function () {
    catatBayar(['2026-08']);
    $kepsek = User::factory()->create()->assignRole('kepala_sekolah');

    $this->actingAs($kepsek)->get('/les-ekskul/laporan-harian')
        ->assertInertia(fn (Assert $page) => $page->component('les-ekskul/laporan-harian/index')
            ->where('periode.id', $this->periode['2026-08']->id)
            ->where('laporan.0.total', 80000));
    $this->actingAs($kepsek)->get("/les-ekskul/periode/{$this->periode['2026-08']->id}")->assertOk();
    aksiLaporan($kepsek, 'laporan-harian/'.LaporanHarian::sole()->id.'/ajukan')->assertForbidden();
});
