<?php

namespace App\Domains\LesEkskul\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

// Pembagian porsi pengajar per pertemuan saat pengajar berganti di tengah bulan (Ringkasan v1.11 #50).
// Setiap tanggal pertemuan di bulan tagihan bernilai sama dan dibagi rata ke pengajar yang aktif pada tanggal itu;
// tanggal tanpa pengajar aktif tidak dihitung. Libur sekolah tidak diperhitungkan (belum ada kalender libur)
class PembagianPertemuanService
{
    // Tanggal di bulan kalender $bulan yang hari ISO-nya (1 = Senin … 7 = Minggu) ada di $hari; kosong → hari sekolah
    public function tanggalPertemuan(CarbonInterface $bulan, array $hari): array
    {
        $hari = array_values(array_unique(array_map('intval', $hari)));

        return $this->tanggalBulan($bulan, $hari === [] ? [1, 2, 3, 4, 5] : $hari);
    }

    // Hari sekolah Senin–Jumat, dipakai les tambahan yang tidak punya jadwal
    public function hariSekolah(CarbonInterface $bulan): array
    {
        return $this->tanggalBulan($bulan, [1, 2, 3, 4, 5]);
    }

    // $penugasan: daftar ['kunci' => id penerima, 'mulai' => ?date, 'selesai' => ?date]; satu kunci boleh punya beberapa baris.
    // Hasil: bobot per kunci (jumlahnya 1), jumlah pertemuan tiap kunci, dan jumlah tanggal yang punya pengajar.
    // bobot kosong = tidak ada satu pun tanggal yang punya pengajar aktif
    public function bobot(Collection $penugasan, array $tanggal): array
    {
        $bobot = [];
        $hadir = [];
        $terisi = 0;

        foreach ($tanggal as $t) {
            $aktif = $penugasan
                ->filter(fn (array $p) => ($p['mulai'] === null || $p['mulai'] <= $t) && ($p['selesai'] === null || $p['selesai'] >= $t))
                ->pluck('kunci')
                ->unique()
                ->values();

            if ($aktif->isEmpty()) {
                continue;
            }

            $terisi++;
            foreach ($aktif as $kunci) {
                $bobot[$kunci] = ($bobot[$kunci] ?? 0) + 1 / $aktif->count();
                $hadir[$kunci] = ($hadir[$kunci] ?? 0) + 1;
            }
        }

        return [
            'bobot' => $terisi === 0 ? [] : array_map(fn (float $b) => $b / $terisi, $bobot),
            'hadir' => $hadir,
            'jumlah' => $terisi,
        ];
    }

    // Bagi $porsi ke penerima menurut bobot tiap bulan, ditimbang dengan pendapatan bulan itu.
    // $bobotPerBulan: [bulan => [kunci => bobot]]; $pendapatanPerBulan: [bulan => rupiah].
    // Dibulatkan ke bawah; sisa (beberapa rupiah) dikembalikan ke pemanggil lewat selisih dengan $porsi.
    // Tanpa pergantian (bobot sama setiap bulan) hasilnya sama dengan intdiv($porsi, jumlah penerima)
    public function bagi(int $porsi, array $bobotPerBulan, array $pendapatanPerBulan): array
    {
        $total = array_sum(array_intersect_key($pendapatanPerBulan, $bobotPerBulan));
        $jumlahBulan = count($bobotPerBulan);
        $bagian = [];

        foreach ($bobotPerBulan as $bulan => $bobot) {
            // Bulan berpendapatan 0 tidak menggeser bagian; bila semua 0, setiap bulan sama berat
            $berat = $total > 0 ? ($pendapatanPerBulan[$bulan] ?? 0) / $total : 1 / max($jumlahBulan, 1);
            foreach ($bobot as $kunci => $b) {
                $bagian[$kunci] = ($bagian[$kunci] ?? 0) + $berat * $b;
            }
        }

        // 1e-6 menahan galat pecahan (mis. 30.000 × 1/3 = 9.999,999…) tanpa menggeser rupiah
        $hasil = array_map(fn (float $b) => (int) floor($porsi * $b + 1e-6), $bagian);

        // Pengaman: jumlah tidak boleh melebihi porsi
        while (array_sum($hasil) > $porsi) {
            $kunci = array_search(max($hasil), $hasil, true);
            $hasil[$kunci]--;
        }

        return $hasil;
    }

    private function tanggalBulan(CarbonInterface $bulan, array $hariIso): array
    {
        $awal = CarbonImmutable::parse($bulan)->startOfMonth();
        $tanggal = [];

        for ($t = $awal; $t->month === $awal->month; $t = $t->addDay()) {
            if (in_array($t->dayOfWeekIso, $hariIso, true)) {
                $tanggal[] = $t->toDateString();
            }
        }

        return $tanggal;
    }
}
