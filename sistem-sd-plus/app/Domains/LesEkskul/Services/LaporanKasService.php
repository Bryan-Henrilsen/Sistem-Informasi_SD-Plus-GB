<?php

namespace App\Domains\LesEkskul\Services;

use App\Domains\LesEkskul\Enums\KategoriKas;
use App\Domains\LesEkskul\Enums\PeranHonor;
use App\Domains\LesEkskul\Enums\StatusTransaksi;
use App\Domains\LesEkskul\Models\HonorDetail;
use App\Domains\LesEkskul\Models\HonorKegiatan;
use App\Domains\LesEkskul\Models\KasManual;
use App\Domains\LesEkskul\Models\PeriodeLaporan;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Laporan Keuangan Kas per periode (A6 #28, Ringkasan v1.11 #35), mengikuti laporan keuangan kegiatan sekolah
// tetapi memakai uang riil: porsi 5% pendamping tidak keluar dari kas, hanya pendamping kedua dst. (FR-16d).
// Saldo kas sekolah dan kas yayasan dibawa dari periode sebelumnya; titipan dihitung ulang setiap periode
class LaporanKasService
{
    public function __construct(private HonorService $honor) {}

    // Angka dari kegiatan baru ada setelah honor dihitung; periode tanpa honor sama sekali juga dianggap siap
    public function siap(PeriodeLaporan $periode): bool
    {
        return $periode->sudahDitutup() && ($this->honor->sudahDihitung($periode) || ! $this->honor->adaYangDihitung($periode));
    }

    public function terkunci(PeriodeLaporan $periode): bool
    {
        return $periode->honor_diketahui_at !== null;
    }

    public function susun(PeriodeLaporan $periode): array
    {
        $saldo = ['sekolah' => 0, 'yayasan' => 0];
        $belumLengkap = [];

        // Saldo berantai dari periode paling awal; periode yang belum siap hanya menyumbang catatan manualnya
        foreach (PeriodeLaporan::where('bulan', '<', $periode->bulan->toDateString())->orderBy('bulan')->get() as $lalu) {
            $mutasi = $this->mutasi($lalu);
            $saldo['sekolah'] += $mutasi['sekolah']->sum(fn (array $b) => $b['masuk'] - $b['keluar']);
            $saldo['yayasan'] += $mutasi['yayasan']->sum(fn (array $b) => $b['masuk'] - $b['keluar']);

            if (! $mutasi['siap']) {
                $belumLengkap[] = $lalu->bulan->toDateString();
            }
        }

        $mutasi = $this->mutasi($periode);
        $sekolah = $this->bagian($saldo['sekolah'], $mutasi['sekolah']);
        $yayasan = $this->bagian($saldo['yayasan'], $mutasi['yayasan']);
        $titipan = $this->titipan($periode);

        return [
            'siap' => $mutasi['siap'],
            'terkunci' => $this->terkunci($periode),
            'periode_belum_lengkap' => $belumLengkap,
            'sekolah' => $sekolah,
            'yayasan' => $yayasan,
            'titipan' => $titipan,
            'saldo_total' => $sekolah['saldo'] + $yayasan['saldo'] + $titipan,
        ];
    }

    // Bendahara / Kepala Sekolah mencatat kas manual selama periode belum "Sudah Diketahui"
    public function catat(PeriodeLaporan $periode, KategoriKas $kategori, int $nominal, string $tanggal, ?string $uraian, User $oleh): KasManual
    {
        return DB::transaction(function () use ($periode, $kategori, $nominal, $tanggal, $uraian, $oleh) {
            $periode = PeriodeLaporan::whereKey($periode->id)->lockForUpdate()->firstOrFail();
            $uraian = trim((string) $uraian) ?: null;

            $masalah = match (true) {
                $this->terkunci($periode) => ['periode', 'Periode ini sudah ditandai Sudah Diketahui; kas tidak bisa diubah.'],
                $nominal <= 0 => ['nominal', 'Nominal harus lebih dari 0.'],
                $kategori->butuhUraian() && $uraian === null => ['uraian', 'Uraian wajib diisi untuk pemasukan/pengeluaran lain.'],
                // Saldo awal hanya di periode pertama; periode berikutnya memakai saldo yang dibawa
                $kategori->saldoAwal() && PeriodeLaporan::where('bulan', '<', $periode->bulan->toDateString())->exists() => ['kategori', 'Saldo awal hanya bisa dicatat di periode pertama.'],
                default => null,
            };

            if ($masalah) {
                throw ValidationException::withMessages([$masalah[0] => $masalah[1]]);
            }

            return KasManual::create([
                'periode_laporan_id' => $periode->id,
                'tanggal' => $tanggal,
                'kategori' => $kategori,
                'uraian' => $uraian,
                'nominal' => $nominal,
                'dicatat_oleh' => $oleh->id,
            ]);
        });
    }

    public function hapus(KasManual $kas): void
    {
        DB::transaction(function () use ($kas) {
            $periode = PeriodeLaporan::whereKey($kas->periode_laporan_id)->lockForUpdate()->firstOrFail();

            if ($this->terkunci($periode)) {
                throw ValidationException::withMessages(['periode' => 'Periode ini sudah ditandai Sudah Diketahui; kas tidak bisa diubah.']);
            }

            $kas->delete();
        });
    }

    // Baris pemasukan/pengeluaran satu periode untuk Kas Sekolah dan Kas Yayasan
    private function mutasi(PeriodeLaporan $periode): array
    {
        $siap = $this->siap($periode);
        $sekolah = collect();
        $yayasan = collect();

        if ($siap) {
            $detail = HonorDetail::where('periode_laporan_id', $periode->id)->with(['honorKegiatan', 'honorFlatPengaturan.jenis'])->get();
            $ekskul = $detail->filter(fn (HonorDetail $d) => $d->honorKegiatan?->ekstrakurikuler_id !== null);
            $les = $detail->filter(fn (HonorDetail $d) => $d->honorKegiatan?->les_tambahan_id !== null);

            // Pendamping tambahan: setiap pendamping menerima porsi penuh, jadi semua kecuali satu per kegiatan diambil dari kas
            $pendampingTambahan = $ekskul->where('peran', PeranHonor::GuruPendamping)->groupBy('honor_kegiatan_id')
                ->sum(fn (Collection $baris) => $baris->sum('nominal') - $baris->first()->nominal);
            $nonHonor = HonorKegiatan::where('periode_laporan_id', $periode->id)->get()->sum(fn (HonorKegiatan $k) => $k->total_pendapatan - $k->dasar_honor);

            $sekolah->push($this->baris('Kas dari Ekstra', $ekskul->where('peran', PeranHonor::KasSekolah)->sum('nominal') + $pendampingTambahan, 0));
            $sekolah->push($this->baris('Kas dari Les', $les->where('peran', PeranHonor::KasSekolah)->sum('nominal'), 0));
            $sekolah->push($this->baris('Uang kolam masuk (bagian non-honor)', $nonHonor, 0));

            $detail->where('peran', PeranHonor::HonorFlat)
                ->groupBy(fn (HonorDetail $d) => $d->honorFlatPengaturan->jenis->id)
                ->sortKeys()
                ->each(fn (Collection $baris) => $sekolah->push($this->baris('Honor '.$baris->first()->honorFlatPengaturan->jenis->nama, 0, $baris->sum('nominal'))));

            $sekolah->push($this->baris('Pendamping tambahan (dari kas)', 0, $pendampingTambahan));
            $yayasan->push($this->baris('Kas Yayasan dari uang les', $les->where('peran', PeranHonor::Yayasan)->sum('nominal'), 0));
        }

        foreach ($periode->kasManual()->with('dicatatOlehUser')->orderBy('tanggal')->orderBy('id')->get() as $kas) {
            $baris = $this->baris(
                $kas->kategori->label().($kas->uraian ? ": {$kas->uraian}" : ''),
                $kas->kategori->masuk() ? $kas->nominal : 0,
                $kas->kategori->masuk() ? 0 : $kas->nominal,
                $kas,
            );
            $kas->kategori->kasYayasan() ? $yayasan->push($baris) : $sekolah->push($baris);
        }

        // Baris otomatis yang nol tidak ditampilkan, kecuali kas dari kegiatan supaya laporan tetap berbentuk sama
        $tampil = fn (array $b) => $b['manual'] !== null || $b['masuk'] > 0 || $b['keluar'] > 0 || str_starts_with($b['uraian'], 'Kas ');

        return ['siap' => $siap, 'sekolah' => $sekolah->filter($tampil)->values(), 'yayasan' => $yayasan->filter($tampil)->values()];
    }

    // Saldo berjalan setiap baris, mulai dari saldo periode lalu
    private function bagian(int $saldoLalu, Collection $baris): array
    {
        $saldo = $saldoLalu;

        return [
            'saldo_lalu' => $saldoLalu,
            'baris' => $baris->map(function (array $b) use (&$saldo) {
                $saldo += $b['masuk'] - $b['keluar'];

                return $b + ['saldo' => $saldo];
            })->all(),
            'masuk' => $baris->sum('masuk'),
            'keluar' => $baris->sum('keluar'),
            'saldo' => $saldo,
        ];
    }

    // Titipan: pembayaran yang tercatat sampai periode ini untuk tagihan bulan berikutnya (bayar di muka), belum diakui untuk honor
    private function titipan(PeriodeLaporan $periode): int
    {
        return (int) DB::table('alokasi_transaksi as a')
            ->join('tagihan as t', 't.id', '=', 'a.tagihan_id')
            ->join('transaksi as tr', 'tr.id', '=', 'a.transaksi_id')
            ->join('laporan_harian as lh', 'lh.id', '=', 'tr.laporan_harian_id')
            ->join('periode_laporan as p', 'p.id', '=', 'lh.periode_laporan_id')
            ->where('t.bulan_tagihan', '>', $periode->bulan->toDateString())
            ->where('p.bulan', '<=', $periode->bulan->toDateString())
            ->where('tr.status', '!=', StatusTransaksi::Ditolak->value)
            ->sum('a.nominal');
    }

    private function baris(string $uraian, int $masuk, int $keluar, ?KasManual $kas = null): array
    {
        return [
            'uraian' => $uraian,
            'masuk' => $masuk,
            'keluar' => $keluar,
            'manual' => $kas ? [
                'id' => $kas->id,
                'tanggal' => $kas->tanggal->toDateString(),
                'dicatat_oleh' => $kas->dicatatOlehUser?->name,
            ] : null,
        ];
    }
}
