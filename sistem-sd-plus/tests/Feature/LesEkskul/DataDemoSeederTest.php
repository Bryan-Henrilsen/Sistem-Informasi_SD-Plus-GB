<?php

use App\Domains\JurnalGuru\Models\ForumPesan;
use App\Domains\LesEkskul\Enums\StatusKonfirmasiTransfer;
use App\Domains\LesEkskul\Enums\StatusLaporanHarian;
use App\Domains\LesEkskul\Enums\StatusPenyesuaian;
use App\Domains\LesEkskul\Enums\StatusPeriodeLaporan;
use App\Domains\LesEkskul\Enums\StatusTransaksi;
use App\Domains\LesEkskul\Models\HonorDetail;
use App\Domains\LesEkskul\Models\KonfirmasiTransfer;
use App\Domains\LesEkskul\Models\LaporanHarian;
use App\Domains\LesEkskul\Models\PenyesuaianDana;
use App\Domains\LesEkskul\Models\PeriodeLaporan;
use App\Domains\LesEkskul\Models\Transaksi;
use App\Domains\LesEkskul\Services\PendaftaranService;
use App\Models\Siswa;
use App\Models\User;
use Database\Seeders\DataDemoSeeder;

// Seeder data demo (docs/Panduan_Demo.md): seluruh riwayat dibuat lewat service, keadaan akhir sesuai skenario demo.
// Jumat (laporan kemarin = Kamis) dan Senin (laporan "kemarin" = Jumat, melewati akhir pekan)
test('data demo menghasilkan keadaan akhir skenario demo', function (string $hariDemo, string $hariDiajukan) {
    $this->travelTo($hariDemo.' 10:00');
    $this->seed(DataDemoSeeder::class);
    $this->travelTo($hariDemo.' 10:00'); // seeder mengembalikan jam di akhir

    $periode = PeriodeLaporan::orderBy('bulan')->get()->keyBy(fn ($p) => $p->bulan->format('Y-m'));

    expect($periode->map(fn ($p) => $p->status)->all())->toBe([
        '2026-08' => StatusPeriodeLaporan::Ditutup,
        '2026-09' => StatusPeriodeLaporan::Ditutup,
        '2026-10' => StatusPeriodeLaporan::Ditutup,
        '2026-11' => StatusPeriodeLaporan::Diajukan,
        '2026-12' => StatusPeriodeLaporan::Terbuka,
    ])
        ->and($periode['2026-09']->honor_diketahui_at)->not->toBeNull()
        ->and($periode['2026-10']->honor_diketahui_at)->toBeNull()
        // Honor Agustus–Oktober terhitung otomatis saat tutup buku (data pengajar & penerima peran lengkap)
        ->and(collect(['2026-08', '2026-09', '2026-10'])->every(fn ($b) => HonorDetail::where('periode_laporan_id', $periode[$b]->id)->exists()))->toBeTrue();

    // Hari demo: laporan masih draft dengan transfer pending; laporan sebelumnya menunggu Bendahara
    $draft = LaporanHarian::where('tanggal', $hariDemo)->sole();
    $diajukan = LaporanHarian::where('tanggal', $hariDiajukan)->sole();
    expect($draft->status)->toBe(StatusLaporanHarian::Draft)
        ->and($draft->transaksi()->where('status', StatusTransaksi::Pending)->exists())->toBeTrue()
        ->and($diajukan->status)->toBe(StatusLaporanHarian::Diajukan)
        ->and(LaporanHarian::whereNotIn('id', [$draft->id, $diajukan->id])->where('status', '!=', StatusLaporanHarian::Disetujui)->exists())->toBeFalse();

    // Akun orang tua demo: dua anak, transfer Titi ditolak, konfirmasi Theo menunggu TU
    $ortu = User::where('no_hp', '081200000100')->sole();
    $titi = Siswa::where('nama', 'Titi Tarihoran')->sole();
    expect($ortu->anak()->count())->toBe(2)
        ->and(Transaksi::where('siswa_id', $titi->id)->where('status', StatusTransaksi::Ditolak)->exists())->toBeTrue()
        ->and(KonfirmasiTransfer::where('status', StatusKonfirmasiTransfer::Menunggu)->count())->toBe(2)
        ->and(PenyesuaianDana::where('status', StatusPenyesuaian::Proses)->count())->toBe(1)
        ->and(app(PendaftaranService::class)->periodeBuka())->not->toBeNull()
        ->and(ForumPesan::whereNull('dibaca_at')->exists())->toBeTrue();
})->with([
    'Jumat' => ['2026-11-27', '2026-11-26'],
    'Senin' => ['2026-11-30', '2026-11-27'],
]);

test('data demo menolak dijalankan di luar periode Desember', function () {
    $this->travelTo('2027-01-05 10:00');

    expect(fn () => $this->seed(DataDemoSeeder::class))->toThrow(RuntimeException::class);
});
