<?php

use App\Domains\LesEkskul\Models\Ekstrakurikuler;
use App\Domains\LesEkskul\Models\LesTambahan;
use App\Domains\LesEkskul\Models\Pendaftaran;
use App\Domains\LesEkskul\Models\PeriodeLaporan;
use App\Domains\LesEkskul\Models\Tagihan;
use App\Domains\LesEkskul\Services\TagihanService;
use App\Models\Kelas;
use App\Models\Siswa;
use App\Models\SiswaKelas;
use App\Models\TahunAjaran;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

// Ekspor Rekap Iuran ke Excel (FR-13b, Ringkasan v1.11 #54): angka per bulan, warna status, sheet per buku, rincian pembayaran
beforeEach(function () {
    $this->seed(RoleSeeder::class);
    $this->travelTo('2026-11-20 09:00');
    $this->tu = User::factory()->create(['name' => 'Serafina Fuijun, A.Md.'])->assignRole('tu');

    $this->ta = TahunAjaran::create(['nama' => '2026/2027', 'tanggal_mulai' => '2026-07-13', 'tanggal_selesai' => '2027-06-30', 'status_aktif' => true]);
    PeriodeLaporan::create(['bulan' => '2026-11-01', 'tanggal_mulai' => '2026-10-23', 'tanggal_selesai' => '2026-11-22']);

    $kelas2A = Kelas::create(['tahun_ajaran_id' => $this->ta->id, 'jenjang' => 2, 'nama_kelas' => '2A']);
    $basket = Ekstrakurikuler::create(['tahun_ajaran_id' => $this->ta->id, 'nama' => 'Basket', 'biaya' => 80000]);
    $les = LesTambahan::create(['kelas_id' => $kelas2A->id, 'biaya' => 50000]);

    $this->budi = Siswa::create(['nama' => 'Budi']);
    SiswaKelas::create(['siswa_id' => $this->budi->id, 'kelas_id' => $kelas2A->id]);
    $this->basketBudi = Pendaftaran::create(['siswa_id' => $this->budi->id, 'ekstrakurikuler_id' => $basket->id, 'tanggal_daftar' => '2026-07-15']);
    $this->lesBudi = Pendaftaran::create(['siswa_id' => $this->budi->id, 'les_tambahan_id' => $les->id, 'tanggal_daftar' => '2026-07-15']);

    foreach ([$this->basketBudi, $this->lesBudi] as $p) {
        foreach (['2026-08-01', '2026-09-01', '2026-10-01', '2026-11-01'] as $bulan) {
            app(TagihanService::class)->buatUntukBulan($p, $bulan);
        }
    }
});

function bukaExcelUnduhan($response): Spreadsheet
{
    $path = tempnam(sys_get_temp_dir(), 'rekap').'.xlsx';
    file_put_contents($path, $response->streamedContent());

    return IOFactory::load($path);
}

test('Excel berisi ringkasan, satu sheet per buku dengan nominal angka & warna status, dan rincian pembayaran', function () {
    $tagihan = fn (Pendaftaran $p, string $bulan) => Tagihan::where('pendaftaran_id', $p->id)->where('bulan_tagihan', "{$bulan}-01")->sole();
    // Basket: Agustus lunas cash, September dibayar sebagian; Oktober tunggakan; November bulan berjalan belum dibayar
    $this->actingAs($this->tu)->post('/les-ekskul/transaksi', [
        'siswa_id' => $this->budi->id, 'metode' => 'cash', 'nominal' => 110000, 'tanggal' => '2026-11-20',
        'tagihan_ids' => [$tagihan($this->basketBudi, '2026-08')->id, $tagihan($this->basketBudi, '2026-09')->id],
    ])->assertSessionHasNoErrors();

    $respon = $this->actingAs($this->tu)->get('/les-ekskul/tagihan/excel?tahun='.$this->ta->id);
    $respon->assertOk()->assertDownload('Rekap_Iuran_2026-2027.xlsx');
    $excel = bukaExcelUnduhan($respon);

    expect($excel->getSheetNames())->toBe(['Ringkasan', 'Les Tambahan', 'Basket', 'Rincian Pembayaran']);

    // Sheet Basket: judul kolom di baris 5, Budi di baris 6; kolom bulan mulai D (No, Nama, Kelas)
    $basket = $excel->getSheetByName('Basket');
    expect($basket->getCell('B6')->getValue())->toBe('Budi')
        ->and($basket->getCell('D5')->getValue())->toBe('Agt 2026')
        ->and($basket->getCell('D6')->getValue())->toBe(80000) // Agustus lunas
        ->and($basket->getCell('E6')->getValue())->toBe(30000) // September sebagian (110.000 − 80.000)
        ->and($basket->getCell('F6')->getValue())->toBe(0) // Oktober tunggakan
        ->and($basket->getCell('G6')->getValue())->toBeNull() // November bulan berjalan, belum dibayar
        ->and($basket->getStyle('D6')->getFill()->getStartColor()->getRGB())->toBe('C6EFCE')
        ->and($basket->getStyle('E6')->getFill()->getStartColor()->getRGB())->toBe('FFEB9C')
        ->and($basket->getStyle('F6')->getFill()->getStartColor()->getRGB())->toBe('FFC7CE')
        ->and($basket->getCell('D7')->getValue())->toBe('=SUM(D6:D6)') // baris TOTAL memakai rumus
        ->and($basket->getCell('D7')->getCalculatedValue())->toEqual(80000);

    // Total dibayar & tunggakan Budi (Sept sisa 50.000 + Okt 80.000)
    $kolomTotal = 'D';
    foreach (range('D', 'Z') as $k) {
        if ($basket->getCell("{$k}5")->getValue() === 'Total Dibayar') {
            $kolomTotal = $k;
            break;
        }
    }
    expect($basket->getCell("{$kolomTotal}6")->getCalculatedValue())->toEqual(110000)
        ->and($basket->getCell(chr(ord($kolomTotal) + 1).'6')->getValue())->toBe(130000);

    // Rincian pembayaran: satu baris per alokasi (Agustus & September dari satu transaksi cash)
    $rincian = $excel->getSheetByName('Rincian Pembayaran');
    expect($rincian->getCell('A4')->getValue())->toBe('Buku')
        ->and($rincian->getCell('B5')->getValue())->toBe('Budi')
        ->and($rincian->getCell('G5')->getValue())->toBe('Cash')
        ->and($rincian->getCell('H5')->getValue())->toBe(80000)
        ->and($rincian->getCell('I5')->getValue())->toBe('SF')
        ->and($rincian->getCell('H6')->getValue())->toBe(30000);

    // Ringkasan: uang masuk Basket Agustus 80.000
    $ringkasan = $excel->getSheetByName('Ringkasan');
    $barisBasket = collect(range(6, 8))->first(fn ($r) => $ringkasan->getCell("A{$r}")->getValue() === 'Basket');
    expect($ringkasan->getCell("B{$barisBasket}")->getValue())->toBe(80000);
});

test('Excel mengikuti buku yang dipilih; bisa diunduh Bendahara & Kepala Sekolah, tidak oleh orang tua', function () {
    $respon = $this->actingAs($this->tu)->get('/les-ekskul/tagihan/excel?tahun='.$this->ta->id.'&buku=les');
    expect(bukaExcelUnduhan($respon)->getSheetNames())->toBe(['Ringkasan', 'Les Tambahan', 'Rincian Pembayaran']);

    $this->actingAs(User::factory()->create()->assignRole('bendahara'))->get('/les-ekskul/tagihan/excel')->assertOk();
    $this->actingAs(User::factory()->create()->assignRole('kepala_sekolah'))->get('/les-ekskul/tagihan/excel')->assertOk();
    $this->actingAs(User::factory()->create(['email' => null, 'no_hp' => '081234567890'])->assignRole('orang_tua'))->get('/les-ekskul/tagihan/excel')->assertForbidden();
});
