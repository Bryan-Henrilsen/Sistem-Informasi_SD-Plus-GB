<?php

namespace App\Domains\LesEkskul\Services;

use App\Models\Kelas;
use App\Models\TahunAjaran;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Shared\Date as TanggalExcel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

// Membaca Excel impor siswa (FR-28, Ringkasan v1.11 #53). File hanya dibaca sekali dari lokasi sementara unggahan
// lalu dibuang oleh PHP; yang dikembalikan hanya daftar baris. Kolom dicari lewat judulnya, bukan posisinya
class PembacaExcelSiswa
{
    public const BARIS_MAKS = 3000; // jauh di atas jumlah siswa satu sekolah

    public const KOLOM_MAKS = 100; // kolom "Rombel Saat Ini" Dapodik ada di sekitar kolom ke-43

    // Template Juli: Nama | Kelas | L/P, dengan pilihan kelas tahun ajaran tujuan dan sheet petunjuk
    public function template(TahunAjaran $tahunAjaran): Spreadsheet
    {
        $kelas = Kelas::where('tahun_ajaran_id', $tahunAjaran->id)->orderBy('jenjang')->orderBy('nama_kelas')->pluck('nama_kelas');
        $buku = new Spreadsheet;

        $data = $buku->getActiveSheet()->setTitle('Data Siswa');
        $data->fromArray(['Nama', 'Kelas', 'L/P'], null, 'A1');
        $data->getStyle('A1:C1')->getFont()->setBold(true);
        $data->getColumnDimension('A')->setWidth(40);
        $data->getColumnDimension('B')->setWidth(10);
        $data->getColumnDimension('C')->setWidth(8);
        $data->freezePane('A2');

        // Pilihan kelas & L/P (daftar kelas ditulis di sheet Petunjuk supaya tidak terbatas 255 karakter)
        $petunjuk = $buku->createSheet()->setTitle('Petunjuk');
        $petunjuk->fromArray([
            ['Template impor siswa — tahun ajaran '.$tahunAjaran->nama],
            ['1. Isi sheet "Data Siswa": satu baris satu siswa, mulai baris 2. Jangan mengubah judul kolom.'],
            ['2. Kelas ditulis seperti di aplikasi, mis. 1A (lihat daftar di bawah). Bisa dipilih dari daftar di sel.'],
            ['3. L/P boleh dikosongkan; nanti dilengkapi dari ekspor Dapodik. NISN tidak perlu diisi.'],
            ['4. Siswa yang sudah ada dicocokkan otomatis lewat nama; nama yang mirip dipilih TU di pratinjau.'],
            [''],
            ['Daftar kelas'],
        ], null, 'A1');
        $petunjuk->getStyle('A1')->getFont()->setBold(true);
        $petunjuk->getStyle('A7')->getFont()->setBold(true);
        foreach ($kelas->values() as $i => $nama) {
            $petunjuk->setCellValue('A'.(8 + $i), $nama);
        }
        $petunjuk->getColumnDimension('A')->setWidth(90);

        $akhir = 7 + max($kelas->count(), 1);
        foreach ([['B', "Petunjuk!\$A\$8:\$A\${$akhir}"], ['C', '"L,P"']] as [$kolom, $rumus]) {
            $validasi = $data->getCell("{$kolom}2")->getDataValidation();
            $validasi->setType(DataValidation::TYPE_LIST)->setShowDropDown(true)->setAllowBlank(true)->setFormula1($rumus);
            $data->setDataValidation("{$kolom}2:{$kolom}1000", clone $validasi);
        }

        $buku->setActiveSheetIndex(0);

        return $buku;
    }

    // Baris template Juli: [no, nama, kelas, jk]
    public function bacaJuli(string $path): array
    {
        $sheet = $this->buka($path, $this->namaSheet($path)[0] ?? null)->getSheet(0);
        [$barisJudul, $kolom] = $this->cariJudul($sheet, ['nama' => ['nama'], 'kelas' => ['kelas']], ['jk' => ['l/p', 'jk', 'jenis kelamin']])
            ?? throw $this->gagal('Kolom "Nama" dan "Kelas" tidak ditemukan. Pakai template dari aplikasi.');

        $hasil = [];
        foreach ($this->barisData($sheet, $barisJudul + 1) as $no => $sel) {
            $nama = Str::squish((string) ($sel[$kolom['nama']] ?? ''));
            $kelas = Str::squish((string) ($sel[$kolom['kelas']] ?? ''));
            if ($nama === '' && $kelas === '') {
                continue;
            }
            $hasil[] = ['no' => $no, 'nama' => $nama, 'kelas' => $kelas, 'jk' => $this->jk($sel[$kolom['jk'] ?? -1] ?? null)];
        }

        return $this->tidakKosong($hasil);
    }

    // Baris ekspor Dapodik (Sheet1 "Daftar Peserta Didik"): [no, nama, nis, nisn, jk, tanggal_lahir, kelas].
    // Kolom sensitif (NIK, KK, alamat, data orang tua) tidak dibaca sama sekali
    public function bacaDapodik(string $path): array
    {
        $wajib = ['nama' => ['nama'], 'kelas' => ['rombel saat ini']];
        $opsional = ['nis' => ['nipd', 'nis'], 'nisn' => ['nisn'], 'jk' => ['jk', 'l/p'], 'tanggal_lahir' => ['tanggal lahir', 'tanggallahir']];

        // Ekspor Dapodik punya banyak sheet (per kelas, rekap); sheet "Sheet1"/"Daftar Peserta Didik" dicoba lebih dulu
        // dan setiap sheet dimuat sendiri-sendiri supaya hemat memori
        $nama = collect($this->namaSheet($path))
            ->sortBy(fn (string $n) => preg_match('/sheet1|daftar|peserta/i', $n) ? 0 : 1)
            ->values();

        foreach ($nama as $namaSheet) {
            $sheet = $this->buka($path, $namaSheet)->getSheet(0);
            if (! $temu = $this->cariJudul($sheet, $wajib, $opsional)) {
                continue;
            }
            [$barisJudul, $kolom] = $temu;

            $hasil = [];
            foreach ($this->barisData($sheet, $barisJudul + 1) as $no => $sel) {
                $nama = Str::squish((string) ($sel[$kolom['nama']] ?? ''));
                $kelas = Str::squish((string) ($sel[$kolom['kelas']] ?? ''));
                if ($nama === '' || $kelas === '') {
                    continue; // baris sub-judul (mis. "Data Ayah") atau baris kosong
                }
                $hasil[] = [
                    'no' => $no,
                    'nama' => $nama,
                    'kelas' => $kelas,
                    'nis' => $this->nomor($sel[$kolom['nis'] ?? -1] ?? null),
                    'nisn' => $this->nomor($sel[$kolom['nisn'] ?? -1] ?? null),
                    'jk' => $this->jk($sel[$kolom['jk'] ?? -1] ?? null),
                    'tanggal_lahir' => $this->tanggal($sel[$kolom['tanggal_lahir'] ?? -1] ?? null),
                ];
            }

            return $this->tidakKosong($hasil);
        }

        throw $this->gagal('Sheet "Daftar Peserta Didik" dengan kolom "Nama" dan "Rombel Saat Ini" tidak ditemukan. Pakai ekspor Dapodik apa adanya.');
    }

    private function namaSheet(string $path): array
    {
        try {
            return IOFactory::createReaderForFile($path)->listWorksheetNames($path);
        } catch (Throwable) {
            throw $this->gagal('File tidak bisa dibaca. Pastikan file Excel (.xls/.xlsx) dan tidak rusak.');
        }
    }

    // Muat satu sheet saja, dibatasi kolom & baris yang wajar untuk data siswa (sheet berformat lebar tidak menghabiskan memori)
    private function buka(string $path, ?string $sheet): Spreadsheet
    {
        try {
            $pembaca = IOFactory::createReaderForFile($path);
            $pembaca->setReadDataOnly(true);
            $pembaca->setReadEmptyCells(false);
            if ($sheet !== null) {
                $pembaca->setLoadSheetsOnly([$sheet]);
            }
            $pembaca->setReadFilter(new class implements IReadFilter
            {
                public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
                {
                    return $row <= PembacaExcelSiswa::BARIS_MAKS && Coordinate::columnIndexFromString($columnAddress) <= PembacaExcelSiswa::KOLOM_MAKS;
                }
            });

            return $pembaca->load($path);
        } catch (Throwable) {
            throw $this->gagal('File tidak bisa dibaca. Pastikan file Excel (.xls/.xlsx) dan tidak rusak.');
        }
    }

    // Baris judul dalam 15 baris pertama yang memuat semua kolom wajib; hasil [nomor baris, [kunci => indeks kolom]]
    private function cariJudul(Worksheet $sheet, array $wajib, array $opsional): ?array
    {
        $batas = min(15, $sheet->getHighestDataRow());
        for ($r = 1; $r <= $batas; $r++) {
            $judul = array_map(fn ($v) => Str::lower(Str::squish((string) $v)), $sheet->rangeToArray("A{$r}:".$sheet->getHighestDataColumn().$r, null, true, false)[0]);
            $kolom = [];
            foreach ($wajib + $opsional as $kunci => $nama) {
                foreach ($judul as $i => $isi) {
                    if (in_array($isi, $nama, true) && ! isset($kolom[$kunci])) {
                        $kolom[$kunci] = $i;
                    }
                }
            }
            if (count(array_intersect_key($kolom, $wajib)) === count($wajib)) {
                return [$r, $kolom];
            }
        }

        return null;
    }

    // [nomor baris Excel => isi sel per indeks kolom]
    private function barisData(Worksheet $sheet, int $mulai): iterable
    {
        $kolomAkhir = $sheet->getHighestDataColumn();
        for ($r = $mulai; $r <= $sheet->getHighestDataRow(); $r++) {
            yield $r => $sheet->rangeToArray("A{$r}:{$kolomAkhir}{$r}", null, true, false)[0];
        }
    }

    private function jk(mixed $nilai): ?string
    {
        $v = Str::upper(Str::squish((string) $nilai));

        return match (true) {
            in_array($v, ['L', 'LAKI-LAKI', 'LAKI LAKI'], true) => 'L',
            in_array($v, ['P', 'PEREMPUAN'], true) => 'P',
            default => null,
        };
    }

    // NIS/NISN: angka dari Excel bisa terbaca 1285.0 → "1285"; teks dipakai apa adanya (nol di depan tetap)
    private function nomor(mixed $nilai): ?string
    {
        if (is_float($nilai) || is_int($nilai)) {
            return (string) (int) $nilai;
        }
        $v = trim((string) $nilai);

        return $v === '' ? null : $v;
    }

    // Tanggal lahir: teks "2018-01-07" (Dapodik) atau nomor tanggal Excel
    private function tanggal(mixed $nilai): ?string
    {
        try {
            if (is_float($nilai) || is_int($nilai)) {
                return CarbonImmutable::instance(TanggalExcel::excelToDateTimeObject($nilai))->toDateString();
            }
            $v = trim((string) $nilai);

            return $v === '' ? null : CarbonImmutable::parse($v)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    private function tidakKosong(array $hasil): array
    {
        return $hasil !== [] ? $hasil : throw $this->gagal('Tidak ada baris siswa di file.');
    }

    private function gagal(string $pesan): ValidationException
    {
        return ValidationException::withMessages(['file' => $pesan]);
    }
}
