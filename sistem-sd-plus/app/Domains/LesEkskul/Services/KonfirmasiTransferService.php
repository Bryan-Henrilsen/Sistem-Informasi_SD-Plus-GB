<?php

namespace App\Domains\LesEkskul\Services;

use App\Domains\LesEkskul\Enums\StatusKonfirmasiTransfer;
use App\Domains\LesEkskul\Models\KonfirmasiTransfer;
use App\Domains\LesEkskul\Models\Tagihan;
use App\Domains\LesEkskul\Models\Transaksi;
use App\Models\Siswa;
use App\Models\User;
use App\Support\NomorHp;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Konfirmasi "Saya sudah bayar" dari orang tua untuk transfer manual (FR-15b, Ringkasan v1.11 #41).
// Konfirmasi hanyalah klaim: tidak masuk laporan harian, rekap, atau honor sampai TU mencatatnya menjadi transaksi.
class KonfirmasiTransferService
{
    private const BULAN_PENDEK = [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

    // Tagihan anak yang boleh dicentang orang tua: belum lunas, tidak sedang di order online,
    // dan tidak sedang dalam konfirmasi lain yang menunggu TU
    public function tagihanBisaDipilih(Siswa $siswa): Builder
    {
        return Tagihan::query()
            ->whereHas('pendaftaran', fn (Builder $q) => $q->where('siswa_id', $siswa->id))
            ->bisaDibayarOnline()
            ->whereDoesntHave('konfirmasiTransfer', fn (Builder $q) => $q->where('status', StatusKonfirmasiTransfer::Menunggu));
    }

    // Rincian halaman Pembayaran: tagihan terpilih, total pas (jumlah sisa), dan usulan berita transfer
    public function rincian(Siswa $siswa, array $tagihanIds): array
    {
        $tagihan = $this->ambilTagihan($siswa, $tagihanIds, kunci: false);

        return [
            'tagihan' => $tagihan->map(fn (Tagihan $t) => [
                'id' => $t->id,
                'kegiatan' => $this->namaKegiatan($t),
                'bulan' => $t->bulan_tagihan->format('Y-m'),
                'sisa' => $this->sisa($t),
            ])->values()->all(),
            'total' => $tagihan->sum(fn (Tagihan $t) => $this->sisa($t)),
            'berita' => $this->beritaTransfer($siswa, $tagihan),
        ];
    }

    // Orang tua menekan "Saya sudah bayar"; nominal selalu total sisa tagihan terpilih (tidak bisa diubah)
    public function ajukan(User $orangTua, Siswa $siswa, array $tagihanIds, string $tanggalTransfer, ?string $namaPengirim): KonfirmasiTransfer
    {
        $this->pastikanAnak($orangTua, $siswa);

        return DB::transaction(function () use ($orangTua, $siswa, $tagihanIds, $tanggalTransfer, $namaPengirim) {
            $tagihan = $this->ambilTagihan($siswa, $tagihanIds, kunci: true);

            $konfirmasi = KonfirmasiTransfer::create([
                'siswa_id' => $siswa->id,
                'diajukan_oleh' => $orangTua->id,
                'nominal' => $tagihan->sum(fn (Tagihan $t) => $this->sisa($t)),
                'tanggal_transfer' => Carbon::parse($tanggalTransfer)->toDateString(),
                'nama_pengirim' => $namaPengirim ?: null,
            ]);
            $konfirmasi->tagihan()->attach($tagihan->pluck('id'));

            return $konfirmasi;
        });
    }

    // Orang tua membatalkan konfirmasi yang belum diproses TU (mis. salah pilih tagihan)
    public function batalkan(User $orangTua, KonfirmasiTransfer $konfirmasi): void
    {
        $this->pastikanAnak($orangTua, $konfirmasi->siswa);

        DB::transaction(function () use ($konfirmasi) {
            $this->kunciMenunggu($konfirmasi, 'Konfirmasi ini sudah diproses TU dan tidak bisa dibatalkan.')
                ->update(['status' => StatusKonfirmasiTransfer::Dibatalkan]);
        });
    }

    // TU menolak, mis. transfer tidak ada di mutasi; alasannya ditampilkan ke orang tua
    public function tolak(KonfirmasiTransfer $konfirmasi, User $tu, string $alasan): void
    {
        DB::transaction(function () use ($konfirmasi, $tu, $alasan) {
            $this->kunciMenunggu($konfirmasi, 'Konfirmasi ini sudah diproses.')->update([
                'status' => StatusKonfirmasiTransfer::Ditolak,
                'alasan_ditolak' => $alasan,
                'diproses_oleh' => $tu->id,
                'diproses_at' => now(),
            ]);
        });
    }

    // TU mencatat konfirmasi menjadi transaksi: $catat memanggil TransaksiService::catat. Keduanya dalam satu transaksi
    // database, sehingga konfirmasi tidak pernah "dicatat" tanpa transaksinya (atau sebaliknya)
    public function catatTransaksi(KonfirmasiTransfer $konfirmasi, User $tu, callable $catat): Transaksi
    {
        return DB::transaction(function () use ($konfirmasi, $tu, $catat) {
            $konfirmasi = $this->kunciMenunggu($konfirmasi, 'Konfirmasi ini sudah diproses.');
            $transaksi = $catat();

            $konfirmasi->update([
                'status' => StatusKonfirmasiTransfer::Dicatat,
                'transaksi_id' => $transaksi->id,
                'diproses_oleh' => $tu->id,
                'diproses_at' => now(),
            ]);

            return $transaksi;
        });
    }

    // Daftar konfirmasi yang menunggu TU, terlama dulu
    public function daftarMenunggu(): Collection
    {
        return KonfirmasiTransfer::menunggu()
            ->with(['siswa.kelasTahunAktif.kelas', 'pengaju:id,name,no_hp', 'tagihan.pendaftaran.lesTambahan.kelas', 'tagihan.pendaftaran.ekstrakurikuler.induk'])
            ->orderBy('created_at')
            ->get()
            ->map(fn (KonfirmasiTransfer $k) => [
                'id' => $k->id,
                'siswa' => ['id' => $k->siswa->id, 'nama' => $k->siswa->nama, 'kelas' => $k->siswa->kelasTahunAktif?->kelas?->nama_lengkap],
                'pengaju' => ['nama' => $k->pengaju->name, 'no_hp' => $k->pengaju->no_hp],
                'nominal' => $k->nominal,
                'tanggal_transfer' => $k->tanggal_transfer->toDateString(),
                'nama_pengirim' => $k->nama_pengirim,
                'diajukan_at' => $k->created_at->toIso8601String(),
                'tagihan' => $k->tagihan->sortBy('bulan_tagihan')->values()
                    ->map(fn (Tagihan $t) => $this->namaKegiatan($t).' '.$this->bulanPendek($t->bulan_tagihan)),
            ]);
    }

    // Konfirmasi satu anak yang masih menunggu atau ditolak (tahun berjalan), untuk halaman Tagihan orang tua
    public function untukOrangTua(Siswa $siswa): Collection
    {
        return KonfirmasiTransfer::where('siswa_id', $siswa->id)
            ->whereIn('status', [StatusKonfirmasiTransfer::Menunggu, StatusKonfirmasiTransfer::Ditolak])
            ->where('created_at', '>=', today()->subMonths(3))
            ->with(['siswa.kelasTahunAktif.kelas', 'tagihan.pendaftaran.lesTambahan.kelas', 'tagihan.pendaftaran.ekstrakurikuler.induk'])
            ->latest()
            ->get()
            ->map(fn (KonfirmasiTransfer $k) => [
                'id' => $k->id,
                'status' => $k->status,
                'nominal' => $k->nominal,
                'tanggal_transfer' => $k->tanggal_transfer->toDateString(),
                'alasan' => $k->alasan_ditolak,
                'berita' => $this->beritaTransfer($k->siswa, $k->tagihan),
                'whatsapp' => $k->status === StatusKonfirmasiTransfer::Menunggu ? $this->tautanWhatsApp($this->pesanWhatsApp($k)) : null,
            ]);
    }

    // Usulan berita transfer: nama depan + kelas + kegiatan & bulan, mis. "Budi 2A Les Sep-Okt, Basket Okt"
    public function beritaTransfer(Siswa $siswa, Collection $tagihan): string
    {
        $namaDepan = strtok(trim($siswa->nama), ' ') ?: $siswa->nama;
        $kelas = $siswa->kelasTahunAktif?->kelas?->nama_kelas;

        $kegiatan = $tagihan->sortBy('bulan_tagihan')
            ->groupBy(fn (Tagihan $t) => $this->namaKegiatanPendek($t))
            ->map(fn (Collection $isi, string $nama) => $nama.' '.$this->rentangBulan($isi->pluck('bulan_tagihan')))
            ->implode(', ');

        return trim(implode(' ', array_filter([$namaDepan, $kelas, $kegiatan])));
    }

    // Tautan WhatsApp ke TU dengan pesan terisi; null bila WA_TU belum diatur
    public function tautanWhatsApp(string $pesan): ?string
    {
        $nomor = NomorHp::internasional(config('les_ekskul.wa_tu'));

        return $nomor ? "https://wa.me/{$nomor}?text=".rawurlencode($pesan) : null;
    }

    private function pesanWhatsApp(KonfirmasiTransfer $k): string
    {
        $kelas = $k->siswa->kelasTahunAktif?->kelas?->nama_lengkap;

        return "Halo TU SD Plus Gembala Baik, saya orang tua {$k->siswa->nama}".($kelas ? " ({$kelas})" : '').'. '
            .'Saya sudah transfer Rp'.number_format($k->nominal, 0, ',', '.').' pada '.$k->tanggal_transfer->locale('id')->translatedFormat('j F Y')
            .' untuk '.$this->beritaTransfer($k->siswa, $k->tagihan).'. Berikut bukti transfernya.';
    }

    // Tagihan terpilih milik anak ini dan masih bisa dipilih; kunci = lockForUpdate saat menyimpan
    private function ambilTagihan(Siswa $siswa, array $tagihanIds, bool $kunci): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $tagihanIds)));
        $tagihan = $this->tagihanBisaDipilih($siswa)
            ->whereKey($ids)
            ->with('pendaftaran.lesTambahan.kelas', 'pendaftaran.ekstrakurikuler.induk')
            ->withSum('transaksi as terbayar', 'alokasi_transaksi.nominal')
            ->when($kunci, fn (Builder $q) => $q->lockForUpdate())
            ->orderBy('bulan_tagihan')
            ->get();

        if ($ids === [] || $tagihan->count() !== count($ids)) {
            throw ValidationException::withMessages([
                'tagihan' => 'Sebagian tagihan sudah lunas atau sedang menunggu verifikasi TU. Muat ulang halaman Tagihan lalu pilih lagi.',
            ]);
        }

        return $tagihan;
    }

    private function pastikanAnak(User $orangTua, Siswa $siswa): void
    {
        abort_unless($orangTua->anak()->whereKey($siswa->id)->exists(), 404); // anak orang lain dianggap tidak ada
    }

    private function kunciMenunggu(KonfirmasiTransfer $konfirmasi, string $pesan): KonfirmasiTransfer
    {
        $konfirmasi = KonfirmasiTransfer::whereKey($konfirmasi->id)->lockForUpdate()->firstOrFail();

        if ($konfirmasi->status !== StatusKonfirmasiTransfer::Menunggu) {
            throw ValidationException::withMessages(['konfirmasi' => $pesan]);
        }

        return $konfirmasi;
    }

    private function sisa(Tagihan $t): int
    {
        return $t->harus_dibayar - (int) ($t->terbayar ?? $t->totalAlokasi());
    }

    // Bulan berturut-turut (menurut bulan yang ditagih, jadi Nov → Jan juga berturut) digabung: "Sep-Nov"
    private function rentangBulan(Collection $bulan): string
    {
        $kelompok = [];

        foreach ($bulan->sort()->values() as $b) {
            $akhir = array_key_last($kelompok);

            if ($akhir !== null && $this->bulanDitagihBerikutnya($kelompok[$akhir][1])->isSameMonth($b)) {
                $kelompok[$akhir][1] = $b;
            } else {
                $kelompok[] = [$b, $b];
            }
        }

        return collect($kelompok)
            ->map(fn (array $r) => $r[0]->isSameMonth($r[1]) ? $this->bulanPendek($r[0]) : $this->bulanPendek($r[0]).'-'.$this->bulanPendek($r[1]))
            ->implode(' ');
    }

    private function bulanDitagihBerikutnya($bulan)
    {
        $bulan = $bulan->startOfMonth()->addMonth();

        while (in_array($bulan->month, config('les_ekskul.bulan_tidak_ditagih'), true)) {
            $bulan = $bulan->addMonth();
        }

        return $bulan;
    }

    private function bulanPendek($bulan): string
    {
        return self::BULAN_PENDEK[$bulan->month];
    }

    private function namaKegiatan(Tagihan $t): string
    {
        $p = $t->pendaftaran;

        return $p->lesTambahan ? 'Les Tambahan' : ($p->ekstrakurikuler->induk?->nama ?? $p->ekstrakurikuler->nama);
    }

    // Nama singkat untuk berita transfer: "Les" untuk les tambahan, nama ekskul induk untuk ekskul
    private function namaKegiatanPendek(Tagihan $t): string
    {
        return $t->pendaftaran->lesTambahan ? 'Les' : $this->namaKegiatan($t);
    }
}
