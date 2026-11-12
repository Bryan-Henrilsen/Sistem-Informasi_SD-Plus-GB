<?php

namespace App\Domains\LesEkskul\Services;

use App\Domains\LesEkskul\Enums\JenisPenyesuaian;
use App\Domains\LesEkskul\Enums\MetodeTransaksi;
use App\Domains\LesEkskul\Enums\StatusApprovalBendahara;
use App\Domains\LesEkskul\Enums\StatusPenyesuaian;
use App\Domains\LesEkskul\Enums\StatusTagihan;
use App\Domains\LesEkskul\Enums\StatusTransaksi;
use App\Domains\LesEkskul\Enums\SumberInput;
use App\Domains\LesEkskul\Models\LaporanHarian;
use App\Domains\LesEkskul\Models\PenyesuaianDana;
use App\Domains\LesEkskul\Models\PeriodeLaporan;
use App\Domains\LesEkskul\Models\Tagihan;
use App\Domains\LesEkskul\Models\Transaksi;
use App\Models\Siswa;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Pencatatan transaksi cash/transfer dan pembagiannya ke tagihan (FR-7, FR-8c, FR-21, FR-21a)
class TransaksiService
{
    public function __construct(private TagihanService $tagihanService) {}

    // Catat uang masuk lalu bagi ke tagihan yang dicentang TU; semua atau tidak sama sekali
    public function catat(
        Siswa $siswa,
        MetodeTransaksi $metode,
        int $nominal,
        array $tagihanIds,
        User $oleh,
        SumberInput $sumber = SumberInput::Tu,
        CarbonInterface|string|null $tanggal = null,
        ?string $catatanTransfer = null,
    ): Transaksi {
        $tanggal = $tanggal ? Carbon::parse($tanggal)->toImmutable()->startOfDay() : today(); // jamnya dibuang: yang dicatat hanya tanggal
        $this->periksaInput($metode, $sumber, $nominal, $tanggal);

        return DB::transaction(function () use ($siswa, $metode, $nominal, $tagihanIds, $oleh, $sumber, $tanggal, $catatanTransfer) {
            $tagihan = $this->ambilTagihan($siswa, $tagihanIds);

            // Cash dibayar di loket, jadi kelebihannya langsung dikembalikan; tidak ada kelebihan cash (FR-21 hanya transfer)
            if ($metode === MetodeTransaksi::Cash && $nominal > $tagihan->sum(fn (Tagihan $t) => $t->sisa())) {
                throw $this->gagal('nominal', 'Nominal cash melebihi tagihan yang dipilih; berikan kembaliannya');
            }

            $transaksi = Transaksi::create([
                'siswa_id' => $siswa->id,
                'laporan_harian_id' => $this->laporanPencatatan()->id,
                'tanggal' => $tanggal->toDateString(),
                'metode' => $metode,
                'nominal' => $nominal,
                'catatan_transfer' => $metode === MetodeTransaksi::Transfer ? $catatanTransfer : null,
                'sumber_input' => $sumber,
                'diinput_oleh' => $oleh->id,
                ...$this->statusAwal($metode, $sumber, $oleh),
            ]);

            $sisaUang = $this->alokasikan($transaksi, $tagihan, $nominal);

            if ($sisaUang > 0) {
                $sisaUang = $this->alihkanKelebihan($transaksi, $tagihan->last(), $sisaUang);
            }

            if ($sisaUang > 0) {
                $this->catatRefundKelebihan($transaksi, $tagihan->last(), $sisaUang, $oleh);
            }

            return $transaksi->load('tagihan');
        });
    }

    // FR-8: TU memverifikasi transfer pending setelah mengecek bukti yang dikirim orang tua di luar aplikasi
    public function verifikasi(Transaksi $transaksi, User $oleh): Transaksi
    {
        return DB::transaction(function () use ($transaksi, $oleh) {
            $transaksi = $this->kunci($transaksi);

            if ($transaksi->metode !== MetodeTransaksi::Transfer || $transaksi->status !== StatusTransaksi::Pending) {
                throw $this->gagal('transaksi', 'Hanya transfer yang berstatus pending yang bisa diverifikasi.');
            }

            $transaksi->update([
                'status' => StatusTransaksi::Terverifikasi,
                'diverifikasi_oleh' => $oleh->id,
                'diverifikasi_at' => now(),
            ]);

            return $transaksi;
        });
    }

    // FR-8b: Bendahara mencocokkan transfer yang sudah diverifikasi TU dengan mutasi rekening
    public function setujui(Transaksi $transaksi, User $oleh): Transaksi
    {
        return DB::transaction(function () use ($transaksi, $oleh) {
            $transaksi = $this->kunci($transaksi);

            if ($transaksi->status !== StatusTransaksi::Terverifikasi || $transaksi->status_approval_bendahara !== StatusApprovalBendahara::BelumDicek) {
                throw $this->gagal('transaksi', 'Hanya transfer yang sudah diverifikasi TU dan belum dicek yang bisa disetujui.');
            }

            $transaksi->update([
                'status_approval_bendahara' => StatusApprovalBendahara::Disetujui,
                'diapprove_oleh' => $oleh->id,
                'tanggal_approval_bendahara' => now(),
            ]);

            return $transaksi;
        });
    }

    // FR-8d: transfer tidak valid ditolak TU atau Bendahara. Transaksi tidak dihapus; alokasinya dilepas sehingga tagihan kembali 'belum', dan refund kelebihan yang belum dikirim ikut dihapus (Ringkasan v1.11 #25)
    public function tolak(Transaksi $transaksi, User $oleh, string $alasan): Transaksi
    {
        $alasan = trim($alasan);

        if ($alasan === '') {
            throw $this->gagal('alasan', 'Alasan penolakan wajib diisi.');
        }

        return DB::transaction(function () use ($transaksi, $oleh, $alasan) {
            $transaksi = $this->kunci($transaksi);

            // Penolakan final; transfer yang sudah cocok dengan mutasi rekening tidak bisa ditolak
            if ($transaksi->metode !== MetodeTransaksi::Transfer
                || $transaksi->status === StatusTransaksi::Ditolak
                || $transaksi->status_approval_bendahara === StatusApprovalBendahara::Disetujui) {
                throw $this->gagal('transaksi', 'Hanya transfer yang belum disetujui Bendahara dan belum ditolak yang bisa ditolak');
            }

            if ($transaksi->penyesuaianDana()->where('status', StatusPenyesuaian::Berhasil)->exists()) {
                throw $this->gagal('transaksi', 'Kelebihan transfer ini sudah dikembalikan ke orang tua. Selesaikan dulu langsung dengan orang tua.');
            }

            $tagihan = $transaksi->tagihan()->lockForUpdate()->get();
            $transaksi->tagihan()->detach(); // lepas semua alokasi transaksi ini

            $tagihan->each(fn (Tagihan $t) => $this->perbaruiStatus($t)); // lunas -> belum, kecuali dibayar transaksi lain

            $transaksi->penyesuaianDana()->delete(); // refund 'proses': kelebihannya ternyata tidak pernah ada

            $transaksi->update([
                'status' => StatusTransaksi::Ditolak,
                'alasan_ditolak' => $alasan,
                'ditolak_oleh' => $oleh->id,
                'ditolak_at' => now(),
            ]);

            return $transaksi;
        });
    }

    // Ambil ulang baris transaksi sambil menguncinya, agar verifikasi/persetujuan/penolakan tidak bertabrakan
    private function kunci(Transaksi $transaksi): Transaksi
    {
        return Transaksi::whereKey($transaksi->id)->lockForUpdate()->firstOrFail();
    }

    private function periksaInput(MetodeTransaksi $metode, SumberInput $sumber, int $nominal, CarbonInterface $tanggal): void
    {
        if ($metode === MetodeTransaksi::Online || $sumber === SumberInput::Sistem) {
            throw $this->gagal('metode', 'Pembayaran online dicatat otomatis dari gateway.'); // FR-30
        }

        if ($sumber === SumberInput::Bendahara && $metode !== MetodeTransaksi::Transfer) {
            throw $this->gagal('metode', 'Bendahara hanya menambahkan transaksi transfer dari mutasi rekening.'); // (FR-8c)
        }

        if ($nominal <= 0) {
            throw $this->gagal('nominal', 'Nominal harus lebih dari 0');
        }

        if ($tanggal->isAfter(today())) {
            throw $this->gagal('tanggal', 'Tanggal uang masuk tidak boleh di masa depan');
        }
    }

    // Tagihan yang dicentang: milik siswa ini, belum lunas, tidak sedang di order online; dikunci agar tidak dibayar double
    private function ambilTagihan(Siswa $siswa, array $tagihanIds): Collection
    {
        $tagihan = Tagihan::whereKey($tagihanIds)
            ->whereHas('pendaftaran', fn (Builder $q) => $q->where('siswa_id', $siswa->id))
            ->bisaDibayarOnline()
            ->orderBy('bulan_tagihan')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($tagihanIds === [] || $tagihan->count() !== count(array_unique($tagihanIds))) {
            throw $this->gagal('tagihan', 'Pilih tagihan siswa ini yang belum lunas dan tidak sedang dibayar online');
        }

        return $tagihan;
    }

    private function statusAwal(MetodeTransaksi $metode, SumberInput $sumber, User $oleh): array
    {
        $terverifikasi = [
            'status' => StatusTransaksi::Terverifikasi,
            'diverifikasi_oleh' => $oleh->id,
            'diverifikasi_at' => now(),
        ];

        return match (true) {
            // Cash langsung terverifikasi dan tidak melalui approval Bendahara (FR-7, FR-8b)
            $metode === MetodeTransaksi::Cash => $terverifikasi,

            // Transfer yang ditemukan Bendahara di mutasi rekening langsung terverifikasi dan disetujui (FR-8c)
            $sumber === SumberInput::Bendahara => [
                ...$terverifikasi,
                'status_approval_bendahara' => StatusApprovalBendahara::Disetujui,
                'diapprove_oleh' => $oleh->id,
                'tanggal_approval_bendahara' => now()
            ],

            // Transfer yang dicatat TU menunggu verifikasi (FR-8) dan approval Bendahara (FR-8b)
            default => [
                'status' => StatusTransaksi::Pending,
                'status_approval_bendahara' => StatusApprovalBendahara::BelumDicek,
            ],
        };
    }

    // Laporan harian hari pencatatan; bila sudah disetujui, pindah ke hari kerja berikutnya (Ringkasan v1.11 #15)
    private function laporanPencatatan(): LaporanHarian
    {
        $hari = today();

        do {
            $laporan = $this->laporanTanggal($hari);
            $hari = $hari->addWeekday(); // Senin-Jumat; Sabtu dan Minggu
        } while ($laporan->sudahDisetujui());

        if ($laporan->bisaDitarikKembali()) {
            throw $this->gagal('laporan', 'Laporan harian ' . $laporan->tanggal->format('d/m/Y') . ' sedang diajukan ke Bendahara. Tarik kembali laporan itu dulu, lalu catat transaksinya');
        }

        return $laporan;
    }

    // Baris laporan harian dibuat (draft) saat transaksi pertama hari itu dicatat (Ringkasan v1.11 #12)
    private function laporanTanggal(CarbonInterface $hari): LaporanHarian
    {
        $laporan = LaporanHarian::where('tanggal', $hari->toDateString())->lockForUpdate()->first();

        if ($laporan) {
            return $laporan;
        }

        $periode = PeriodeLaporan::mencakup($hari)->first();

        if (! $periode || $periode->sudahDitutup()) {
            throw $this->gagal('laporan', 'Belum ada periode laporan terbuka untuk tanggal ' . $hari->format('d/m/Y'));
        }

        return LaporanHarian::create(['periode_laporan_id' => $periode->id, 'tanggal' => $hari->toDateString()]);
    }

    // Bagi uang ke tagihan urut bulan terlama; tagihan terakhir boleh terbayar sebagian (FR-21a). Hasilnya sisa uang
    private function alokasikan(Transaksi $transaksi, Collection $tagihan, int $uang): int
    {
        foreach ($tagihan as $t) {
            if ($uang === 0) {
                break;
            }

            $bayar = min($uang, $t->sisa());

            if ($bayar > 0) {
                $transaksi->tagihan()->attach($t->id, ['nominal' => $bayar]);
                // satu per satu: isi pivot berbeda-beda
                $this->perbaruiStatus($t);
                $uang -= $bayar;
            }
        }

        return $uang;
    }

    // Kelebihan transfer mengurangi tagihan bulan berikutnya untuk kegiatan yang sama, hanya di semester yang sama;
    // tagihannya dibuat bila belum ada (FR-21, Ringkasan v1.11 #22)
    private function alihkanKelebihan(Transaksi $transaksi, Tagihan $terakhir, int $uang): int
    {
        $pendaftaran = $terakhir->pendaftaran;
        $bulan = $terakhir->bulan_tagihan->addMonth();

        while ($uang > 0) {
            $baru = $this->tagihanService->buatUntukBulan($pendaftaran, $bulan);

            // Bulan tidak ditagih = akhir semester (Desember, Juni, Mei untuk kelas 6) atau siswa sudah berhenti
            if (! $baru) {
                break;
            }

            $tagihan = Tagihan::whereKey($baru->id)->bisaDibayarOnline()->lockForUpdate()->get(); // kosong bila sudah lunas
            $uang = $this->alokasikan($transaksi, $tagihan, $uang);
            $bulan = $bulan->addMonth();
        }

        return $uang;
    }

    // Sisa yang tidak punya bulan tujuan di semester ini dikembalikan kepada orang tua;
    // Status 'proses' sampai TU menandai 'berhasil' setelah uang ditransfer balik
    private function catatRefundKelebihan(Transaksi $transaksi, Tagihan $terakhir, int $uang, User $oleh): void
    {
        PenyesuaianDana::create([
            'pendaftaran_id' => $terakhir->pendaftaran_id,
            'transaksi_id' => $transaksi->id,
            'jenis_penyesuaian' => JenisPenyesuaian::KelebihanTransfer,
            'periode_asal_id' => $transaksi->laporanHarian->periode_laporan_id,
            'nominal' => $uang,
            'status' => StatusPenyesuaian::Proses,
            'tanggal_proses' => today(),
            'diproses_oleh' => $oleh->id,
        ]);
    }

    // Lunas bila total alokasi = nominal - diskon (A4 alokasi_transaksi)
    private function perbaruiStatus(Tagihan $tagihan): void
    {
        $tagihan->update(['status' => $tagihan->sisa() === 0 ? StatusTagihan::Lunas : StatusTagihan::Belum]);
    }

    private function gagal(string $field, string $pesan): ValidationException
    {
        return ValidationException::withMessages([$field => $pesan]);
    }

}
