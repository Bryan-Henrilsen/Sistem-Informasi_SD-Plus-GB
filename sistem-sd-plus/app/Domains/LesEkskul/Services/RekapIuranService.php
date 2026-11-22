<?php

namespace App\Domains\LesEkskul\Services;

use App\Domains\LesEkskul\Enums\MetodeTransaksi;
use App\Domains\LesEkskul\Enums\StatusTagihan;
use App\Domains\LesEkskul\Enums\StatusTransaksi;
use App\Domains\LesEkskul\Models\KegiatanLibur;
use App\Domains\LesEkskul\Models\Pendaftaran;
use App\Domains\LesEkskul\Models\Tagihan;
use App\Domains\LesEkskul\Models\Transaksi;
use App\Models\TahunAjaran;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

// Rekap Iuran tahunan: pengganti buku rekap manual TU (FR-13b, Ringkasan v1.11 #40)
class RekapIuranService
{
    public const BUKU_LES = 'les';

    // Kolom bulan: dari bulan mulai tahun ajaran sampai bulan selesai, tanpa bulan yang tidak pernah ditagih
    public function bulan(TahunAjaran $tahunAjaran): array
    {
        $bulan = $tahunAjaran->tanggal_mulai->startOfMonth();
        $hasil = [];

        while ($bulan->lte($tahunAjaran->tanggal_selesai)) {
            if (! in_array($bulan->month, config('les_ekskul.bulan_tidak_ditagih'), true)) {
                $hasil[] = $bulan->format('Y-m');
            }
            $bulan = $bulan->addMonth();
        }

        return $hasil;
    }

    // Semua baris rekap tahun ajaran ini (satu baris = satu pendaftaran), urut buku → kelas → abjad
    public function baris(TahunAjaran $tahunAjaran): Collection
    {
        $awalBulanIni = today()->startOfMonth();
        $libur = $this->bulanLibur($tahunAjaran);

        return Pendaftaran::query()
            ->whereHas('tagihan') // kegiatan berbiaya 0 (Olimpiade) tidak punya tagihan
            ->where(fn (Builder $q) => $q
                ->whereHas('lesTambahan', fn (Builder $q) => $q->where('tahun_ajaran_id', $tahunAjaran->id))
                ->orWhereHas('ekstrakurikuler', fn (Builder $q) => $q->where('tahun_ajaran_id', $tahunAjaran->id)))
            ->with([
                'siswa.riwayatKelas' => fn ($q) => $q->where('tahun_ajaran_id', $tahunAjaran->id)->with('kelas'),
                'lesTambahan.kelas',
                'ekstrakurikuler.induk',
                'tagihan.transaksi.diinputOlehUser:id,name',
            ])
            ->get()
            ->map(fn (Pendaftaran $p) => $this->satuBaris($p, $awalBulanIni, $libur))
            ->sort(fn (array $a, array $b) => [$a['urut_buku'], $a['urut_kelas'], $a['siswa']['nama']]
                <=> [$b['urut_buku'], $b['urut_kelas'], $b['siswa']['nama']])
            ->values();
    }

    // Daftar buku untuk tombol pilihan: Les Tambahan dulu, lalu ekstrakurikuler urut abjad
    public function daftarBuku(Collection $baris): array
    {
        return $baris->groupBy('buku')
            ->map(fn (Collection $isi, string $kunci) => [
                'kunci' => $kunci,
                'nama' => $isi->first()['nama_buku'],
                'jumlah' => $isi->count(),
            ])
            ->sortBy(fn (array $b) => $b['kunci'] === self::BUKU_LES ? '0' : '1'.$b['nama'])
            ->values()
            ->all();
    }

    // Tab Ringkasan: per buku per bulan jumlah tagihan, yang lunas, dan uang yang sudah membayar bulan itu; plus tunggakan per buku
    public function ringkasan(Collection $baris, array $bulan): array
    {
        return collect($this->daftarBuku($baris))->map(function (array $buku) use ($baris, $bulan) {
            $isi = $baris->where('buku', $buku['kunci']);

            return [
                ...$buku,
                'bulan' => collect($bulan)->mapWithKeys(function (string $b) use ($isi) {
                    $kotak = $isi->pluck("bulan.{$b}")->filter(fn ($k) => $k && ! in_array($k['status'], ['batal', 'libur'], true));

                    return [$b => [
                        'total' => $kotak->count(),
                        'lunas' => $kotak->whereIn('status', ['lunas', 'menunggu'])->count(),
                        'terbayar' => $kotak->sum(fn (array $k) => $k['harus_dibayar'] - $k['sisa']),
                    ]];
                }),
                'tunggakan' => $isi->sum('tunggakan'),
            ];
        })->all();
    }

    // Paraf pencatat = inisial dua kata pertama nama (tanpa gelar di belakang koma); online dicatat sistem
    public static function paraf(?User $user, MetodeTransaksi $metode): string
    {
        if ($metode === MetodeTransaksi::Online || ! $user) {
            return 'sistem';
        }

        return Str::of($user->name)->before(',')->squish()->explode(' ')
            ->take(2)
            ->map(fn (string $kata) => Str::upper(Str::substr($kata, 0, 1)))
            ->implode('');
    }

    // Kegiatan tidak berlangsung (FR-23, #48): ['les-<id>' / 'ekskul-<id>' => ['Y-m' => alasan]]
    private function bulanLibur(TahunAjaran $tahunAjaran): Collection
    {
        return KegiatanLibur::whereBetween('bulan', [$tahunAjaran->tanggal_mulai->startOfMonth()->toDateString(), $tahunAjaran->tanggal_selesai->toDateString()])
            ->get()
            ->groupBy(fn (KegiatanLibur $l) => $l->les_tambahan_id ? "les-{$l->les_tambahan_id}" : "ekskul-{$l->ekstrakurikuler_id}")
            ->map(fn (Collection $isi) => $isi->mapWithKeys(fn (KegiatanLibur $l) => [$l->bulan->format('Y-m') => $l->alasan]));
    }

    private function satuBaris(Pendaftaran $pendaftaran, $awalBulanIni, Collection $libur): array
    {
        $kelas = $pendaftaran->adalahLesTambahan()
            ? $pendaftaran->lesTambahan->kelas
            : $pendaftaran->siswa->riwayatKelas->first()?->kelas;
        $ekskul = $pendaftaran->ekstrakurikuler;
        $induk = $ekskul?->induk;

        // Penandaan ekskul induk berlaku juga untuk kelas paralelnya
        $bulanLibur = $pendaftaran->adalahLesTambahan()
            ? $libur->get("les-{$pendaftaran->les_tambahan_id}", collect())
            : $libur->get("ekskul-{$ekskul->id}", collect())->union($induk ? $libur->get("ekskul-{$induk->id}", collect()) : []);

        $kotak = $pendaftaran->tagihan->mapWithKeys(fn (Tagihan $t) => [
            $t->bulan_tagihan->format('Y-m') => $this->kotak($t, $awalBulanIni, $bulanLibur->has($t->bulan_tagihan->format('Y-m'))),
        ]);

        return [
            'id' => $pendaftaran->id,
            'buku' => $pendaftaran->adalahLesTambahan() ? self::BUKU_LES : 'ekskul-'.($induk?->id ?? $ekskul->id),
            'nama_buku' => $pendaftaran->adalahLesTambahan() ? 'Les Tambahan' : ($induk?->nama ?? $ekskul->nama),
            'urut_buku' => $pendaftaran->adalahLesTambahan() ? '0' : '1'.($induk?->nama ?? $ekskul->nama),
            'siswa' => ['id' => $pendaftaran->siswa->id, 'nama' => $pendaftaran->siswa->nama],
            'kelas_id' => $kelas?->id,
            'kelas' => $kelas?->nama_lengkap,
            'urut_kelas' => $kelas ? sprintf('%d %s', $kelas->jenjang, $kelas->nama_kelas) : '9', // tanpa kelas di paling bawah
            'kegiatan' => $induk ? $ekskul->nama : null, // nama kelas paralel, mis. English Club Flyer
            'status' => $pendaftaran->status,
            'bulan' => $kotak,
            'libur' => $bulanLibur, // bulan kegiatan tidak berlangsung => alasan; kotaknya tampil "libur" walau tagihannya tidak ada
            'tunggakan' => $kotak->whereNotIn('status', ['batal', 'libur'])
                ->filter(fn (array $k, string $b) => $k['status'] !== 'lunas' && $k['status'] !== 'menunggu' && $b < $awalBulanIni->format('Y-m'))
                ->sum('sisa'),
            'menunggu' => $kotak->contains('ada_pending', true), // ada transfer yang belum diverifikasi TU
        ];
    }

    // Satu kotak bulan: status warna + rincian pembayaran (tanggal, metode, nominal, paraf) seperti di buku
    private function kotak(Tagihan $tagihan, $awalBulanIni, bool $libur): array
    {
        $bayar = $tagihan->transaksi->sortBy('tanggal')->values()->map(fn (Transaksi $t) => [
            'transaksi_id' => $t->id,
            'tanggal' => $t->tanggal->toDateString(),
            'metode' => $t->metode,
            'nominal' => (int) $t->pivot->nominal,
            'paraf' => self::paraf($t->diinputOlehUser, $t->metode),
            'pencatat' => $t->diinputOlehUser?->name,
            'status' => $t->status,
        ]);
        $sisa = $tagihan->harus_dibayar - $bayar->sum('nominal');

        $status = match (true) {
            $tagihan->status === StatusTagihan::Dibatalkan => $libur ? 'libur' : 'batal',
            $tagihan->status === StatusTagihan::Lunas => $bayar->contains('status', StatusTransaksi::Pending) ? 'menunggu' : 'lunas',
            $sisa < $tagihan->harus_dibayar => 'sebagian',
            $tagihan->bulan_tagihan->lt($awalBulanIni) => 'tunggakan',
            default => 'belum',
        };

        return [
            'tagihan_id' => $tagihan->id,
            'status' => $status,
            'harus_dibayar' => $tagihan->harus_dibayar,
            'diskon' => $tagihan->diskon,
            'sisa' => max(0, $sisa),
            'bayar' => $bayar,
            'ada_pending' => $bayar->contains('status', StatusTransaksi::Pending),
        ];
    }
}
