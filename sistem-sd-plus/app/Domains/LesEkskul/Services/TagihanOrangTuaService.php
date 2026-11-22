<?php

namespace App\Domains\LesEkskul\Services;

use App\Domains\LesEkskul\Enums\StatusKonfirmasiTransfer;
use App\Domains\LesEkskul\Enums\StatusPembayaranOnline;
use App\Domains\LesEkskul\Enums\StatusTagihan;
use App\Domains\LesEkskul\Enums\StatusTransaksi;
use App\Domains\LesEkskul\Models\KegiatanLibur;
use App\Domains\LesEkskul\Models\Pendaftaran;
use App\Domains\LesEkskul\Models\Tagihan;
use App\Domains\LesEkskul\Models\Transaksi;
use App\Models\Siswa;
use App\Models\TahunAjaran;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

// Status pembayaran & tunggakan untuk orang tua (FR-15, FR-32; Ringkasan v1.11 #38).
// Tagihan yang dibayar transfer tercatat lunas begitu TU mencatatnya; bagi orang tua statusnya "menunggu verifikasi"
// sampai TU memverifikasi. Transfer yang ditolak alokasinya dilepas, jadi ditampilkan terpisah beserta alasannya
class TagihanOrangTuaService
{
    // Tagihan tahun ajaran aktif satu anak, terbaru dulu, dengan status yang dimengerti orang tua
    public function tagihan(Siswa $siswa): Collection
    {
        $tahunAjaran = TahunAjaran::aktif()->first();

        if (! $tahunAjaran) {
            return collect();
        }

        $bulanIni = today()->startOfMonth();

        return Tagihan::query()
            ->whereHas('pendaftaran', fn (Builder $q) => $q->where('siswa_id', $siswa->id))
            ->where('status', '!=', StatusTagihan::Dibatalkan)
            // Tahun ajaran aktif, ditambah tagihan tahun ajaran sebelumnya yang belum lunas (tetap tunggakan, Ringkasan v1.11 #45)
            ->where(fn (Builder $q) => $q
                ->whereBetween('bulan_tagihan', [$tahunAjaran->tanggal_mulai->toDateString(), $tahunAjaran->tanggal_selesai->toDateString()])
                ->orWhere(fn (Builder $q) => $q->where('bulan_tagihan', '<', $tahunAjaran->tanggal_mulai->toDateString())->where('status', StatusTagihan::Belum)))
            ->with(['pendaftaran.lesTambahan.kelas', 'pendaftaran.ekstrakurikuler.induk', 'transaksi', 'pembayaranOnline',
                'konfirmasiTransfer' => fn ($q) => $q->where('status', StatusKonfirmasiTransfer::Menunggu)])
            ->orderByDesc('bulan_tagihan')
            ->orderBy('id')
            ->get()
            ->map(function (Tagihan $t) use ($bulanIni) {
                $dibayar = (int) $t->transaksi->sum('pivot.nominal');
                $sisa = $t->harus_dibayar - $dibayar;
                $menungguOnline = $t->pembayaranOnline->contains(fn ($o) => $o->status === StatusPembayaranOnline::Menunggu);
                $menungguVerifikasi = $t->transaksi->contains(fn (Transaksi $tr) => $tr->status === StatusTransaksi::Pending);
                $dikonfirmasi = $t->konfirmasiTransfer->isNotEmpty(); // orang tua sudah menekan "Saya sudah bayar" (#41)

                $status = match (true) {
                    $sisa <= 0 && $menungguVerifikasi => 'menunggu_verifikasi',
                    $sisa > 0 && $dikonfirmasi => 'menunggu_verifikasi',
                    $sisa <= 0 => 'lunas',
                    $menungguOnline => 'menunggu_online',
                    $dibayar > 0 => 'sebagian',
                    default => 'belum',
                };

                return [
                    'id' => $t->id,
                    'bulan' => $t->bulan_tagihan->toDateString(),
                    'kegiatan' => $this->namaKegiatan($t),
                    'nominal' => $t->nominal,
                    'diskon' => $t->diskon,
                    'harus_dibayar' => $t->harus_dibayar,
                    'dibayar' => $dibayar,
                    'sisa' => max($sisa, 0),
                    'status' => $status,
                    // Tunggakan = bulan yang sudah lewat dan belum lunas (scope Tagihan::tunggakan)
                    'tunggakan' => $sisa > 0 && ! $dikonfirmasi && $t->bulan_tagihan->lt($bulanIni),
                    // Boleh dicentang untuk konfirmasi transfer: sama dengan KonfirmasiTransferService::tagihanBisaDipilih
                    'bisa_dipilih' => $t->status === StatusTagihan::Belum && ! $menungguOnline && ! $dikonfirmasi,
                ];
            });
    }

    // Ringkasan per anak untuk beranda dan kepala halaman Tagihan
    public function ringkasan(Collection $tagihan): array
    {
        $bulanIni = today()->startOfMonth()->toDateString();

        return [
            'tunggakan' => (int) $tagihan->where('tunggakan', true)->sum('sisa'),
            // Tagihan yang sudah dikonfirmasi "Saya sudah bayar" tidak dihitung belum dibayar (#41)
            'bulan_ini' => (int) $tagihan->where('bulan', $bulanIni)->where('status', '!=', 'menunggu_verifikasi')->sum('sisa'),
            'menunggu_verifikasi' => $tagihan->where('status', 'menunggu_verifikasi')->count(),
        ];
    }

    // Transfer yang ditolak TU/Bendahara beserta alasannya, tahun ajaran aktif (FR-8d, FR-15)
    public function transferDitolak(Siswa $siswa): Collection
    {
        $tahunAjaran = TahunAjaran::aktif()->first();

        return Transaksi::where('siswa_id', $siswa->id)
            ->where('status', StatusTransaksi::Ditolak)
            ->when($tahunAjaran, fn (Builder $q) => $q->where('tanggal', '>=', $tahunAjaran->tanggal_mulai->toDateString()))
            ->orderByDesc('tanggal')
            ->get()
            ->map(fn (Transaksi $tr) => [
                'id' => $tr->id,
                'tanggal' => $tr->tanggal->toDateString(),
                'nominal' => $tr->nominal,
                'alasan' => $tr->alasan_ditolak,
            ]);
    }

    // Kegiatan anak yang tidak berlangsung sebulan penuh di tahun ajaran aktif (FR-23, #48): tidak ditagih bulan itu,
    // uang yang sudah dibayar dialihkan ke bulan berikutnya. Penandaan ekskul induk berlaku juga untuk kelas paralelnya
    public function kegiatanLibur(Siswa $siswa): Collection
    {
        $tahunAjaran = TahunAjaran::aktif()->first();

        if (! $tahunAjaran) {
            return collect();
        }

        $pendaftaran = Pendaftaran::where('siswa_id', $siswa->id)
            ->tahunAjaran($tahunAjaran->id)
            ->with(['lesTambahan.kelas', 'ekstrakurikuler.induk'])
            ->get();

        $ekskulIds = $pendaftaran->flatMap(fn (Pendaftaran $p) => [$p->ekstrakurikuler_id, $p->ekstrakurikuler?->parent_ekstrakurikuler_id])->filter();

        return KegiatanLibur::query()
            ->whereBetween('bulan', [$tahunAjaran->tanggal_mulai->startOfMonth()->toDateString(), $tahunAjaran->tanggal_selesai->toDateString()])
            ->where(fn (Builder $q) => $q
                ->whereIn('les_tambahan_id', $pendaftaran->pluck('les_tambahan_id')->filter())
                ->orWhereIn('ekstrakurikuler_id', $ekskulIds))
            ->with(['lesTambahan.kelas', 'ekstrakurikuler'])
            ->orderByDesc('bulan')
            ->get()
            ->map(fn (KegiatanLibur $l) => [
                'id' => $l->id,
                'kegiatan' => $l->nama_kegiatan,
                'bulan' => $l->bulan->toDateString(),
                'alasan' => $l->alasan,
            ]);
    }

    // Info rekening transfer manual; ditampilkan selama pembayaran online dimatikan (FR-32)
    public function rekening(): ?array
    {
        $rekening = config('les_ekskul.rekening');

        return ! config('les_ekskul.pembayaran_online') && $rekening['nomor'] ? $rekening : null;
    }

    private function namaKegiatan(Tagihan $t): string
    {
        $p = $t->pendaftaran;

        return $p->lesTambahan ? 'Les Tambahan '.$p->lesTambahan->kelas->nama_lengkap : ($p->ekstrakurikuler->induk?->nama ?? $p->ekstrakurikuler->nama);
    }
}
