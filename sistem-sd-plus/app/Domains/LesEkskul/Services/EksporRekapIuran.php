<?php

namespace App\Domains\LesEkskul\Services;

use App\Domains\LesEkskul\Enums\MetodeTransaksi;
use App\Domains\LesEkskul\Enums\StatusPendaftaran;
use App\Domains\LesEkskul\Enums\StatusTransaksi;
use App\Models\TahunAjaran;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

// Ekspor Rekap Iuran ke Excel (FR-13b, Ringkasan v1.11 #54). Isi kotak bulan berupa ANGKA (nominal yang sudah dibayar)
// supaya bisa dijumlahkan & diolah di Excel; warna sel mengikuti status seperti di layar; baris total memakai rumus SUM.
// Sheet: Ringkasan, satu sheet per buku (kegiatan), dan Rincian Pembayaran (tanggal, metode, nominal, paraf).
// Datanya sama persis dengan halaman Rekap Iuran (RekapIuranService), tidak dihitung ulang dengan cara lain
class EksporRekapIuran
{
    private const FORMAT_RUPIAH = '#,##0';

    // Warna latar sel per status kotak (selaras dengan warna di halaman Rekap Iuran)
    private const WARNA = [
        'lunas' => 'C6EFCE',
        'menunggu' => 'DDEBF7',
        'sebagian' => 'FFEB9C',
        'tunggakan' => 'FFC7CE',
        'batal' => 'E7E6E6',
        'libur' => 'DEEAF6',
    ];

    private const KETERANGAN = [
        'lunas' => 'Lunas',
        'menunggu' => 'Transfer menunggu verifikasi TU',
        'sebagian' => 'Dibayar sebagian',
        'tunggakan' => 'Tunggakan (bulan sudah lewat, belum lunas)',
        'batal' => 'Dibatalkan / tidak ditagih',
        'libur' => 'Kegiatan tidak berlangsung',
    ];

    public function __construct(private RekapIuranService $rekap) {}

    public function buat(TahunAjaran $tahunAjaran, ?string $buku = null): Spreadsheet
    {
        $semua = $this->rekap->baris($tahunAjaran);
        $bulan = $this->rekap->bulan($tahunAjaran);
        $baris = $buku ? $semua->where('buku', $buku)->values() : $semua;

        $excel = new Spreadsheet;
        $excel->getProperties()->setTitle("Rekap Iuran {$tahunAjaran->nama}")->setCreator('Sistem Informasi SD Plus Gembala Baik');

        $this->ringkasan($excel->getActiveSheet(), $tahunAjaran, $baris, $bulan);

        $namaSheet = ['Ringkasan' => true, 'Rincian Pembayaran' => true];
        foreach ($this->rekap->daftarBuku($baris) as $b) {
            $sheet = $excel->createSheet()->setTitle($this->namaSheet($b['nama'], $namaSheet));
            $this->sheetBuku($sheet, $tahunAjaran, $b['nama'], $baris->where('buku', $b['kunci'])->values(), $bulan);
        }

        $this->rincian($excel->createSheet()->setTitle('Rincian Pembayaran'), $tahunAjaran, $baris);
        $excel->setActiveSheetIndex(0);

        return $excel;
    }

    // Uang masuk per buku per bulan + persentase tagihan lunas + tunggakan, seperti tab Ringkasan
    private function ringkasan(Worksheet $sheet, TahunAjaran $tahunAjaran, Collection $baris, array $bulan): void
    {
        $sheet->setTitle('Ringkasan');
        $this->judul($sheet, "Ringkasan Rekap Iuran — Tahun Ajaran {$tahunAjaran->nama}");
        $ringkasan = $this->rekap->ringkasan($baris, $bulan);

        // Tabel 1: uang yang sudah membayar tagihan bulan itu
        $r = 4;
        $sheet->setCellValue("A{$r}", 'Uang masuk per bulan tagihan (Rp)')->getStyle("A{$r}")->getFont()->setBold(true);
        $r++;
        $this->kepala($sheet, $r, ['Buku', ...array_map(fn ($b) => $this->labelBulan($b), $bulan), 'Total', 'Tunggakan']);
        $awal = $r + 1;
        foreach ($ringkasan as $i => $b) {
            $baris1 = $awal + $i;
            $sheet->setCellValue("A{$baris1}", $b['nama']);
            foreach (array_values($bulan) as $j => $kunci) {
                $sheet->setCellValue([$j + 2, $baris1], $b['bulan'][$kunci]['terbayar']);
            }
            $akhirBulan = Coordinate::stringFromColumnIndex(count($bulan) + 1);
            $sheet->setCellValue([count($bulan) + 2, $baris1], "=SUM(B{$baris1}:{$akhirBulan}{$baris1})");
            $sheet->setCellValue([count($bulan) + 3, $baris1], $b['tunggakan']);
        }
        $akhir = $awal + max(count($ringkasan), 1) - 1;
        $total = $akhir + 1;
        $this->barisTotal($sheet, $total, $awal, $akhir, 2, count($bulan) + 3);
        $this->formatAngka($sheet, "B{$awal}:".Coordinate::stringFromColumnIndex(count($bulan) + 3).$total);
        $this->garis($sheet, 'A'.($awal - 1).':'.Coordinate::stringFromColumnIndex(count($bulan) + 3).$total);

        // Tabel 2: tagihan lunas / total tagihan per bulan (transfer menunggu verifikasi dihitung lunas, seperti di layar)
        $r = $total + 3;
        $sheet->setCellValue("A{$r}", 'Tagihan lunas per bulan (lunas / jumlah tagihan)')->getStyle("A{$r}")->getFont()->setBold(true);
        $r++;
        $this->kepala($sheet, $r, ['Buku', ...array_map(fn ($b) => $this->labelBulan($b), $bulan)]);
        foreach ($ringkasan as $i => $b) {
            $baris2 = $r + 1 + $i;
            $sheet->setCellValue("A{$baris2}", $b['nama']);
            foreach (array_values($bulan) as $j => $kunci) {
                $isi = $b['bulan'][$kunci];
                $sheet->setCellValue([$j + 2, $baris2], $isi['total'] > 0 ? "{$isi['lunas']}/{$isi['total']}" : '–');
            }
        }
        $this->garis($sheet, "A{$r}:".Coordinate::stringFromColumnIndex(count($bulan) + 1).($r + max(count($ringkasan), 1)));

        $sheet->getColumnDimension('A')->setWidth(28);
        foreach (range(2, count($bulan) + 3) as $k) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($k))->setWidth(13);
        }
        $sheet->freezePane('B6');
    }

    // Satu buku: baris siswa urut kelas → abjad, kolom bulan berisi nominal yang sudah dibayar (angka) dengan warna status
    private function sheetBuku(Worksheet $sheet, TahunAjaran $tahunAjaran, string $nama, Collection $baris, array $bulan): void
    {
        $adaParalel = $baris->contains(fn (array $b) => $b['kegiatan'] !== null);
        $kolomTetap = $adaParalel ? ['No', 'Nama Siswa', 'Kelas', 'Kelas Kegiatan'] : ['No', 'Nama Siswa', 'Kelas'];
        $kBulan = count($kolomTetap) + 1; // indeks kolom bulan pertama
        $kTotal = $kBulan + count($bulan);
        $jumlahKolom = $kTotal + 2;

        $this->judul($sheet, "Rekap Iuran {$nama} — Tahun Ajaran {$tahunAjaran->nama}");
        $sheet->setCellValue('A3', 'Isi kotak bulan = nominal yang sudah dibayar (Rp). Warna: lihat keterangan di bawah tabel. Rincian tanggal, metode, dan paraf ada di sheet "Rincian Pembayaran".');

        $r = 5;
        $this->kepala($sheet, $r, [...$kolomTetap, ...array_map(fn ($b) => $this->labelBulan($b), $bulan), 'Total Dibayar', 'Tunggakan', 'Keterangan']);
        $awal = $r + 1;

        foreach ($baris as $i => $b) {
            $row = $awal + $i;
            $nilai = [$i + 1, $b['siswa']['nama'], $b['kelas'] ?? '–'];
            if ($adaParalel) {
                $nilai[] = $b['kegiatan'] ?? '–';
            }
            $sheet->fromArray($nilai, null, "A{$row}", true);

            foreach (array_values($bulan) as $j => $kunci) {
                $sel = Coordinate::stringFromColumnIndex($kBulan + $j).$row;
                $kotak = $b['bulan'][$kunci] ?? null;
                if (isset($b['libur'][$kunci])) {
                    $kotak = ['status' => 'libur'];
                }
                if (! $kotak) {
                    continue; // tidak ditagih bulan itu (mis. baru mendaftar)
                }

                match ($kotak['status']) {
                    'libur' => $sheet->setCellValue($sel, 'libur'),
                    'batal' => $sheet->setCellValue($sel, 'batal'),
                    'belum' => null, // bulan berjalan/mendatang yang belum dibayar: dibiarkan kosong
                    default => $sheet->setCellValue($sel, $kotak['harus_dibayar'] - $kotak['sisa']),
                };
                if (isset(self::WARNA[$kotak['status']])) {
                    $this->isiWarna($sheet, $sel, self::WARNA[$kotak['status']]);
                }
            }

            $awalBulan = Coordinate::stringFromColumnIndex($kBulan);
            $akhirBulan = Coordinate::stringFromColumnIndex($kTotal - 1);
            $sheet->setCellValue([$kTotal, $row], "=SUM({$awalBulan}{$row}:{$akhirBulan}{$row})");
            $sheet->setCellValue([$kTotal + 1, $row], $b['tunggakan']);
            $sheet->setCellValue([$kTotal + 2, $row], $this->keteranganPendaftaran($b['status']));
        }

        $akhir = $awal + max($baris->count(), 1) - 1;
        $total = $akhir + 1;
        $this->barisTotal($sheet, $total, $awal, $akhir, $kBulan, $kTotal + 1);
        $kolomAkhir = Coordinate::stringFromColumnIndex($jumlahKolom);
        $this->formatAngka($sheet, Coordinate::stringFromColumnIndex($kBulan).$awal.':'.Coordinate::stringFromColumnIndex($kTotal + 1).$total);
        $this->garis($sheet, "A{$r}:{$kolomAkhir}{$total}");
        $sheet->setAutoFilter("A{$r}:{$kolomAkhir}{$akhir}");

        // Keterangan warna
        $k = $total + 2;
        $sheet->setCellValue("B{$k}", 'Keterangan warna')->getStyle("B{$k}")->getFont()->setBold(true);
        foreach (self::KETERANGAN as $status => $label) {
            $k++;
            $this->isiWarna($sheet, "A{$k}", self::WARNA[$status]);
            $sheet->setCellValue("B{$k}", $label);
        }

        $sheet->getColumnDimension('A')->setWidth(5);
        $sheet->getColumnDimension('B')->setWidth(32);
        $sheet->getColumnDimension('C')->setWidth(16);
        if ($adaParalel) {
            $sheet->getColumnDimension('D')->setWidth(22);
        }
        foreach (range($kBulan, $kTotal + 1) as $kol) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($kol))->setWidth(11);
        }
        $sheet->getColumnDimension($kolomAkhir)->setWidth(18);
        $sheet->freezePane(Coordinate::stringFromColumnIndex($kBulan).($r + 1));
        $sheet->getPageSetup()->setOrientation('landscape')->setFitToWidth(1)->setFitToHeight(0);
    }

    // Satu baris per pembayaran: tanggal, metode, nominal, paraf (isi kotak versi buku)
    private function rincian(Worksheet $sheet, TahunAjaran $tahunAjaran, Collection $baris): void
    {
        $this->judul($sheet, "Rincian Pembayaran — Tahun Ajaran {$tahunAjaran->nama}");
        $this->kepala($sheet, 4, ['Buku', 'Nama Siswa', 'Kelas', 'Kelas Kegiatan', 'Bulan Tagihan', 'Tanggal Bayar', 'Metode', 'Nominal', 'Paraf', 'Status']);

        $r = 4;
        foreach ($baris as $b) {
            foreach ($b['bulan'] as $kunci => $kotak) {
                foreach ($kotak['bayar'] as $bayar) {
                    $r++;
                    $sheet->fromArray([
                        $b['nama_buku'], $b['siswa']['nama'], $b['kelas'] ?? '–', $b['kegiatan'] ?? '',
                        $this->labelBulan($kunci), CarbonImmutable::parse($bayar['tanggal'])->format('d/m/Y'),
                        $this->labelMetode($bayar['metode']), $bayar['nominal'], $bayar['paraf'],
                        $bayar['status'] === StatusTransaksi::Pending ? 'Menunggu verifikasi TU' : 'Tercatat',
                    ], null, "A{$r}", true);
                }
            }
        }

        $this->formatAngka($sheet, "H5:H{$r}");
        $this->garis($sheet, "A4:J{$r}");
        $sheet->setAutoFilter("A4:J{$r}");
        foreach (['A' => 20, 'B' => 32, 'C' => 16, 'D' => 20, 'E' => 14, 'F' => 13, 'G' => 10, 'H' => 12, 'I' => 8, 'J' => 22] as $kol => $lebar) {
            $sheet->getColumnDimension($kol)->setWidth($lebar);
        }
        $sheet->freezePane('A5');
    }

    private function judul(Worksheet $sheet, string $judul): void
    {
        $sheet->setCellValue('A1', $judul)->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $sheet->setCellValue('A2', 'SD Plus Gembala Baik · diunduh '.now()->locale('id')->translatedFormat('j F Y H:i'));
        $sheet->getStyle('A2')->getFont()->setItalic(true)->getColor()->setRGB('666666');
    }

    private function kepala(Worksheet $sheet, int $baris, array $judul): void
    {
        $sheet->fromArray($judul, null, "A{$baris}");
        $rentang = "A{$baris}:".Coordinate::stringFromColumnIndex(count($judul)).$baris;
        $sheet->getStyle($rentang)->getFont()->setBold(true);
        $sheet->getStyle($rentang)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F2F2F2');
    }

    // Baris TOTAL berisi rumus SUM sehingga tetap benar bila TU mengubah/menyaring isi sheet
    private function barisTotal(Worksheet $sheet, int $baris, int $awal, int $akhir, int $kolomAwal, int $kolomAkhir): void
    {
        $sheet->setCellValue("A{$baris}", 'TOTAL');
        foreach (range($kolomAwal, $kolomAkhir) as $k) {
            $kol = Coordinate::stringFromColumnIndex($k);
            $sheet->setCellValue("{$kol}{$baris}", "=SUM({$kol}{$awal}:{$kol}{$akhir})");
        }
        $sheet->getStyle("A{$baris}:".Coordinate::stringFromColumnIndex($kolomAkhir).$baris)->getFont()->setBold(true);
    }

    private function formatAngka(Worksheet $sheet, string $rentang): void
    {
        $sheet->getStyle($rentang)->getNumberFormat()->setFormatCode(self::FORMAT_RUPIAH);
    }

    private function garis(Worksheet $sheet, string $rentang): void
    {
        $sheet->getStyle($rentang)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('BFBFBF');
    }

    private function isiWarna(Worksheet $sheet, string $sel, string $rgb): void
    {
        $sheet->getStyle($sel)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($rgb);
    }

    private function labelBulan(string $bulan): string
    {
        return CarbonImmutable::parse("{$bulan}-01")->locale('id')->translatedFormat('M Y');
    }

    private function labelMetode(MetodeTransaksi|string $metode): string
    {
        return match ($metode instanceof MetodeTransaksi ? $metode : MetodeTransaksi::from($metode)) {
            MetodeTransaksi::Cash => 'Cash',
            MetodeTransaksi::Transfer => 'Transfer',
            MetodeTransaksi::Online => 'Online',
        };
    }

    private function keteranganPendaftaran(StatusPendaftaran|string $status): string
    {
        $nilai = $status instanceof StatusPendaftaran ? $status->value : $status;

        return match ($nilai) {
            'aktif' => '',
            'berhenti' => 'Berhenti',
            'pindah' => 'Pindah kegiatan',
            default => ucfirst($nilai),
        };
    }

    // Nama sheet Excel: maks. 31 karakter, tanpa : \ / ? * [ ], dan unik
    private function namaSheet(string $nama, array &$terpakai): string
    {
        $dasar = mb_substr(trim(preg_replace('/[:\\\\\/?*\[\]]/', ' ', $nama)), 0, 28) ?: 'Buku';
        $hasil = $dasar;
        for ($i = 2; isset($terpakai[mb_strtolower($hasil)]); $i++) {
            $hasil = "{$dasar} {$i}";
        }
        $terpakai[mb_strtolower($hasil)] = true;

        return $hasil;
    }
}
