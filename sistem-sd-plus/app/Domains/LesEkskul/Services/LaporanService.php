<?php

namespace App\Domains\LesEkskul\Services;

use App\Domains\LesEkskul\Enums\MetodeTransaksi;
use App\Domains\LesEkskul\Enums\StatusApprovalBendahara;
use App\Domains\LesEkskul\Enums\StatusLaporanHarian;
use App\Domains\LesEkskul\Enums\StatusPeriodeLaporan;
use App\Domains\LesEkskul\Enums\StatusTransaksi;
use App\Domains\LesEkskul\Models\LaporanHarian;
use App\Domains\LesEkskul\Models\PeriodeLaporan;
use App\Domains\LesEkskul\Models\Tagihan;
use App\Domains\LesEkskul\Models\Transaksi;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Laporan harian (FR-12), laporan bulanan & tutup buku (FR-13, FR-14, FR-14b; Ringkasan v1.11 #12, #15, #31).
// Alur harian: draft -> diajukan (TU) -> disetujui / ditolak (Bendahara); TU boleh menarik kembali selama belum diputuskan.
// Alur bulanan: terbuka -> diajukan (TU) -> ditutup (Bendahara), atau dikembalikan ke TU (kembali terbuka).
class LaporanService
{
    public function __construct(private TransaksiService $transaksi) {}

    // ---------- Laporan harian ----------

    // TU lupa mengajukan beberapa hari: ajukan semua laporan draft/ditolak sampai kemarin sekaligus (#32).
    // Laporan hari ini tidak ikut karena mungkin masih ada transaksi
    public function ajukanSemuaSampaiKemarin(User $oleh): int
    {
        return DB::transaction(function () use ($oleh) {
            $laporan = LaporanHarian::whereIn('status', [StatusLaporanHarian::Draft, StatusLaporanHarian::Ditolak])
                ->where('tanggal', '<', today()->toDateString())
                ->whereHas('periodeLaporan', fn (Builder $q) => $q->where('status', '!=', StatusPeriodeLaporan::Ditutup))
                ->orderBy('tanggal')
                ->get();

            $laporan->each(fn (LaporanHarian $l) => $this->ajukanHarian($l, $oleh));

            return $laporan->count();
        });
    }

    // Bendahara menyetujui sekaligus semua transfer di laporan ini yang sudah diverifikasi TU (FR-8b, #32).
    // Transfer yang masih pending tidak ikut: harus diverifikasi TU dulu
    public function setujuiSemuaTransfer(LaporanHarian $laporan, User $oleh): int
    {
        return DB::transaction(function () use ($laporan, $oleh) {
            $transfer = $laporan->transaksi()
                ->where('metode', MetodeTransaksi::Transfer)
                ->where('status', StatusTransaksi::Terverifikasi)
                ->where('status_approval_bendahara', StatusApprovalBendahara::BelumDicek)
                ->get();

            $transfer->each(fn (Transaksi $t) => $this->transaksi->setujui($t, $oleh));

            return $transfer->count();
        });
    }

    public function ajukanHarian(LaporanHarian $laporan, User $oleh): void
    {
        DB::transaction(function () use ($laporan, $oleh) {
            $laporan = $this->kunciHarian($laporan);

            if (! in_array($laporan->status, [StatusLaporanHarian::Draft, StatusLaporanHarian::Ditolak], true)) {
                throw $this->gagal('laporan', 'Hanya laporan draft atau yang ditolak yang bisa diajukan.');
            }

            $laporan->update(['status' => StatusLaporanHarian::Diajukan, 'diajukan_oleh' => $oleh->id, 'diajukan_at' => now()]);
        });
    }

    // Untuk menambah transaksi susulan; kolom pengajuan dikosongkan (#15)
    public function tarikHarian(LaporanHarian $laporan): void
    {
        DB::transaction(function () use ($laporan) {
            $laporan = $this->kunciHarian($laporan);

            if (! $laporan->bisaDitarikKembali()) {
                throw $this->gagal('laporan', 'Hanya laporan yang sedang diajukan yang bisa ditarik kembali.');
            }

            $laporan->update(['status' => StatusLaporanHarian::Draft, 'diajukan_oleh' => null, 'diajukan_at' => null]);
        });
    }

    public function setujuiHarian(LaporanHarian $laporan, User $oleh): void
    {
        DB::transaction(function () use ($laporan, $oleh) {
            $laporan = $this->kunciHarian($laporan);

            if ($laporan->status !== StatusLaporanHarian::Diajukan) {
                throw $this->gagal('laporan', 'Hanya laporan yang sedang diajukan yang bisa disetujui.');
            }

            $laporan->update(['status' => StatusLaporanHarian::Disetujui, 'disetujui_oleh' => $oleh->id, 'disetujui_at' => now()]);
        });
    }

    // TU memperbaiki laporan yang ditolak (mis. menolak transfer yang tidak valid), lalu mengajukannya ulang (#12)
    public function tolakHarian(LaporanHarian $laporan, User $oleh, string $alasan): void
    {
        DB::transaction(function () use ($laporan, $oleh, $alasan) {
            $laporan = $this->kunciHarian($laporan);

            if ($laporan->status !== StatusLaporanHarian::Diajukan) {
                throw $this->gagal('laporan', 'Hanya laporan yang sedang diajukan yang bisa ditolak.');
            }

            $laporan->update([
                'status' => StatusLaporanHarian::Ditolak,
                'alasan_ditolak' => trim($alasan),
                'ditolak_oleh' => $oleh->id,
                'ditolak_at' => now(),
            ]);
        });
    }

    // ---------- Laporan bulanan & tutup buku ----------

    // Alasan laporan bulanan belum bisa diajukan TU; kosong = boleh (#31)
    public function hambatanAjukan(PeriodeLaporan $periode): array
    {
        $hambatan = [];

        if ($periode->status !== StatusPeriodeLaporan::Terbuka) {
            $hambatan[] = 'Laporan bulanan sudah diajukan atau periode sudah ditutup.';
        }

        if (! today()->gt($periode->tanggal_selesai)) {
            $hambatan[] = 'Periode belum berakhir (selesai '.$periode->tanggal_selesai->locale('id')->translatedFormat('j F Y').').';
        }

        $belumDisetujui = $periode->laporanHarian()->where('status', '!=', StatusLaporanHarian::Disetujui)->count();
        if ($belumDisetujui > 0) {
            $hambatan[] = "{$belumDisetujui} laporan harian belum disetujui Bendahara.";
        }

        return $hambatan;
    }

    // Alasan buku belum bisa ditutup Bendahara; kosong = boleh (FR-14b, #31)
    public function hambatanTutupBuku(PeriodeLaporan $periode): array
    {
        $hambatan = [];

        if ($periode->status !== StatusPeriodeLaporan::Diajukan) {
            $hambatan[] = 'Laporan bulanan belum diajukan TU.';
        }

        $sebelumnyaTerbuka = PeriodeLaporan::where('bulan', '<', $periode->bulan->toDateString())
            ->where('status', '!=', StatusPeriodeLaporan::Ditutup)
            ->orderBy('bulan')
            ->first();
        if ($sebelumnyaTerbuka) {
            $hambatan[] = 'Periode '.$sebelumnyaTerbuka->bulan->locale('id')->translatedFormat('F Y').' belum ditutup; tutup buku harus berurutan.';
        }

        $transfer = $this->transferBelumSelesai($periode)->count();
        if ($transfer > 0) {
            $hambatan[] = "{$transfer} transfer belum selesai (masih pending di TU atau belum disetujui Bendahara).";
        }

        return $hambatan;
    }

    public function ajukanBulanan(PeriodeLaporan $periode, User $oleh): void
    {
        DB::transaction(function () use ($periode, $oleh) {
            $periode = $this->kunciPeriode($periode);
            $this->tolakBilaAdaHambatan($this->hambatanAjukan($periode));

            $periode->update(['status' => StatusPeriodeLaporan::Diajukan, 'diajukan_oleh' => $oleh->id, 'diajukan_at' => now()]);
        });
    }

    public function tarikBulanan(PeriodeLaporan $periode): void
    {
        DB::transaction(function () use ($periode) {
            $periode = $this->kunciPeriode($periode);

            if ($periode->status !== StatusPeriodeLaporan::Diajukan) {
                throw $this->gagal('periode', 'Hanya laporan bulanan yang sedang diajukan yang bisa ditarik kembali.');
            }

            $periode->update(['status' => StatusPeriodeLaporan::Terbuka, 'diajukan_oleh' => null, 'diajukan_at' => null]);
        });
    }

    // Bendahara menemukan masalah: laporan kembali ke TU dengan alasan, lalu TU mengajukan ulang (#31)
    public function kembalikanBulanan(PeriodeLaporan $periode, User $oleh, string $alasan): void
    {
        DB::transaction(function () use ($periode, $oleh, $alasan) {
            $periode = $this->kunciPeriode($periode);

            if ($periode->status !== StatusPeriodeLaporan::Diajukan) {
                throw $this->gagal('periode', 'Hanya laporan bulanan yang sedang diajukan yang bisa dikembalikan.');
            }

            $periode->update([
                'status' => StatusPeriodeLaporan::Terbuka,
                'diajukan_oleh' => null,
                'diajukan_at' => null,
                'alasan_dikembalikan' => trim($alasan),
                'dikembalikan_oleh' => $oleh->id,
                'dikembalikan_at' => now(),
            ]);
        });
    }

    // FR-14: tutup buku + pengakuan honor (Catatan v1.8 #3). Hasilnya jumlah alokasi yang diakui di periode ini
    public function tutupBuku(PeriodeLaporan $periode, User $oleh): int
    {
        return DB::transaction(function () use ($periode, $oleh) {
            $periode = $this->kunciPeriode($periode);
            $this->tolakBilaAdaHambatan($this->hambatanTutupBuku($periode));

            $periode->update(['status' => StatusPeriodeLaporan::Ditutup, 'ditutup_oleh' => $oleh->id, 'ditutup_at' => now()]);

            return $this->akuiAlokasi($periode);
        });
    }

    // Transfer yang dicatat di periode ini (lewat laporan harian pencatatannya) dan belum selesai; transfer ditolak tidak dihitung (FR-14b)
    public function transferBelumSelesai(PeriodeLaporan $periode): Builder
    {
        return Transaksi::query()
            ->whereHas('laporanHarian', fn (Builder $q) => $q->where('periode_laporan_id', $periode->id))
            ->where('metode', MetodeTransaksi::Transfer)
            ->where('status', '!=', StatusTransaksi::Ditolak)
            ->where(fn (Builder $q) => $q->where('status', StatusTransaksi::Pending)
                ->orWhere('status_approval_bendahara', '!=', StatusApprovalBendahara::Disetujui)
                ->orWhereNull('status_approval_bendahara'));
    }

    // Pengakuan honor: alokasi yang belum diakui, untuk tagihan bulan periode ini atau sebelumnya (tunggakan),
    // yang uangnya tercatat di periode ini atau sebelumnya. Bayar di muka untuk bulan berikutnya menunggu tutup buku bulannya
    private function akuiAlokasi(PeriodeLaporan $periode): int
    {
        $tagihan = Tagihan::where('bulan_tagihan', '<=', $periode->bulan->toDateString())->select('id');
        $transaksi = Transaksi::where('status', '!=', StatusTransaksi::Ditolak)
            ->whereHas('laporanHarian.periodeLaporan', fn (Builder $q) => $q->where('bulan', '<=', $periode->bulan->toDateString()))
            ->select('id');

        return DB::table('alokasi_transaksi')
            ->whereNull('diakui_periode_id')
            ->whereIn('tagihan_id', $tagihan)
            ->whereIn('transaksi_id', $transaksi)
            ->update(['diakui_periode_id' => $periode->id, 'updated_at' => now()]);
    }

    private function kunciHarian(LaporanHarian $laporan): LaporanHarian
    {
        $laporan = LaporanHarian::whereKey($laporan->id)->lockForUpdate()->firstOrFail();

        if ($laporan->periodeLaporan->sudahDitutup()) {
            throw $this->gagal('laporan', 'Periode laporan ini sudah ditutup.');
        }

        return $laporan;
    }

    private function kunciPeriode(PeriodeLaporan $periode): PeriodeLaporan
    {
        return PeriodeLaporan::whereKey($periode->id)->lockForUpdate()->firstOrFail();
    }

    private function tolakBilaAdaHambatan(array $hambatan): void
    {
        if ($hambatan !== []) {
            throw $this->gagal('periode', implode(' ', $hambatan));
        }
    }

    private function gagal(string $field, string $pesan): ValidationException
    {
        return ValidationException::withMessages([$field => $pesan]);
    }
}
