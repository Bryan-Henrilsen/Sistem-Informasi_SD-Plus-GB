<?php

namespace App\Domains\LesEkskul\Services;

use App\Domains\LesEkskul\Enums\JenisPenyesuaian;
use App\Domains\LesEkskul\Enums\MetodeTransaksi;
use App\Domains\LesEkskul\Enums\StatusApprovalBendahara;
use App\Domains\LesEkskul\Enums\StatusLaporanHarian;
use App\Domains\LesEkskul\Enums\StatusPenyesuaian;
use App\Domains\LesEkskul\Enums\StatusPeriodeLaporan;
use App\Domains\LesEkskul\Enums\StatusTagihan;
use App\Domains\LesEkskul\Enums\StatusTransaksi;
use App\Domains\LesEkskul\Models\LaporanHarian;
use App\Domains\LesEkskul\Models\PenyesuaianDana;
use App\Domains\LesEkskul\Models\PeriodeLaporan;
use App\Domains\LesEkskul\Models\Tagihan;
use App\Domains\LesEkskul\Models\Transaksi;
use App\Models\TahunAjaran;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

// Ringkasan "apa yang menunggu saya" untuk beranda dan badge menu, sesuai role (Ringkasan v1.11 #32).
// Hanya membaca data; semua aksinya tetap lewat TransaksiService / LaporanService
class RingkasanTugasService
{
    public const ROLE_KEUANGAN = ['tu', 'bendahara', 'kepala_sekolah'];

    public function __construct(private LaporanService $laporan, private PendaftaranService $pendaftaran) {}

    // Angka di samping menu sidebar: yang menunggu tindakan role pengguna
    public function badge(User $user): array
    {
        $tu = $user->hasRole('tu');
        $bendahara = $user->hasRole('bendahara');

        return [
            'transaksi' => ($tu ? $this->transferMenungguVerifikasi()->count() : 0)
                + ($bendahara ? $this->transferMenungguBendahara()->count() : 0),
            'laporan_harian' => ($tu ? $this->laporanBelumDiajukan()->count() : 0)
                + ($bendahara ? $this->laporanMenungguBendahara()->count() : 0),
            'refund' => $tu ? $this->refundBelumDikembalikan()->count() : 0,
        ];
    }

    // Kartu tugas di beranda. nada 'tugas' = perlu tindakan role ini; 'info' = menunggu orang lain
    public function kartuTugas(User $user): array
    {
        $kartu = [];

        if ($user->hasRole('tu')) {
            $kartu[] = $this->kartu('Transfer perlu diverifikasi', $this->transferMenungguVerifikasi()->count(),
                'Cocokkan bukti transfer dari orang tua dengan tagihan siswa.', route('les-ekskul.transaksi.index', ['status' => 'pending'], false));
            $kartu[] = $this->kartu('Laporan harian belum diajukan', $this->laporanBelumDiajukan()->count(),
                'Laporan sampai kemarin yang masih draft atau ditolak Bendahara.', route('les-ekskul.laporan-harian.index', ['tindakan' => 1], false));

            [$siap, $dikembalikan] = $this->periodeSiapDiajukan();
            $kartu[] = $this->kartu('Laporan bulanan siap diajukan', $siap->count(),
                $dikembalikan ? "Periode {$this->bulan($dikembalikan)} dikembalikan Bendahara: {$dikembalikan->alasan_dikembalikan}" : 'Periode sudah berakhir dan semua laporan hariannya disetujui.',
                ($siap->first() ?? $dikembalikan) ? route('les-ekskul.periode.show', ($siap->first() ?? $dikembalikan)->id, false) : route('les-ekskul.periode.index', [], false));

            $refund = $this->refundBelumDikembalikan();
            $kartu[] = $this->kartu('Refund belum dikembalikan', $refund->count(),
                'Total Rp'.number_format((int) $refund->sum('nominal'), 0, ',', '.').' yang harus dikembalikan ke orang tua.', route('les-ekskul.refund.index', [], false));

            $tahunAjaran = TahunAjaran::aktif()->first();
            $kartu[] = $this->kartu('Siswa belum ikut ekskul', $tahunAjaran ? $this->pendaftaran->siswaBelumMemenuhiKewajiban($tahunAjaran)->count() : 0,
                'Daftarkan lewat input manual, atau bebaskan bila berkondisi khusus.', route('les-ekskul.pendaftaran.index', ['tab' => 'belum'], false));
        }

        if ($user->hasRole('bendahara')) {
            $kartu[] = $this->kartu('Transfer perlu disetujui', $this->transferMenungguBendahara()->count(),
                'Sudah diverifikasi TU; cocokkan dengan mutasi rekening.', route('les-ekskul.laporan-harian.index', ['tindakan' => 1], false));
            $kartu[] = $this->kartu('Laporan harian perlu disetujui', $this->laporanMenungguBendahara()->count(),
                'Diajukan TU; buka per hari untuk melihat transaksinya.', route('les-ekskul.laporan-harian.index', ['tindakan' => 1], false));

            $diajukan = PeriodeLaporan::where('status', StatusPeriodeLaporan::Diajukan)->orderBy('bulan')->first();
            $kartu[] = $this->kartu('Laporan bulanan menunggu tutup buku', PeriodeLaporan::where('status', StatusPeriodeLaporan::Diajukan)->count(),
                $diajukan ? (implode(' ', $this->laporan->hambatanTutupBuku($diajukan)) ?: "Periode {$this->bulan($diajukan)} siap ditutup.") : 'Diajukan TU setelah periode berakhir.',
                $diajukan ? route('les-ekskul.periode.show', $diajukan->id, false) : route('les-ekskul.periode.index', [], false));

            if (! $user->hasRole('tu')) {
                $kartu[] = $this->kartu('Transfer menunggu verifikasi TU', $this->transferMenungguVerifikasi()->count(),
                    'Belum bisa disetujui sampai TU memverifikasinya.', route('les-ekskul.transaksi.index', ['status' => 'pending'], false), 'info');
            }
        }

        return $kartu;
    }

    // Angka keuangan untuk semua role keuangan (termasuk Kepala Sekolah yang hanya melihat)
    public function ringkasanKeuangan(): array
    {
        $periode = PeriodeLaporan::mencakup(today())->first();
        $hariIni = $this->uangMasuk()->whereDate('tanggal', today());
        $bulanIni = today()->startOfMonth()->toDateString();
        $tagihanBulanIni = Tagihan::where('bulan_tagihan', $bulanIni)->where('status', '!=', StatusTagihan::Dibatalkan);

        return [
            'hari_ini' => (int) $hariIni->sum('nominal'),
            'hari_ini_jumlah' => $hariIni->count(),
            'periode' => $periode ? [
                'id' => $periode->id,
                'bulan' => $periode->bulan->toDateString(),
                'total' => (int) $this->uangMasuk()->whereHas('laporanHarian', fn (Builder $q) => $q->where('periode_laporan_id', $periode->id))->sum('nominal'),
            ] : null,
            'tagihan_bulan_ini' => ['lunas' => (clone $tagihanBulanIni)->where('status', StatusTagihan::Lunas)->count(), 'total' => $tagihanBulanIni->count()],
            'tunggakan' => (int) Tagihan::tunggakan()->withSum('transaksi as terbayar', 'alokasi_transaksi.nominal')->get()
                ->sum(fn (Tagihan $t) => $t->harus_dibayar - (int) $t->terbayar),
        ];
    }

    public function transferMenungguVerifikasi(): Builder
    {
        return Transaksi::where('metode', MetodeTransaksi::Transfer)->where('status', StatusTransaksi::Pending);
    }

    public function transferMenungguBendahara(): Builder
    {
        return Transaksi::where('metode', MetodeTransaksi::Transfer)
            ->where('status', StatusTransaksi::Terverifikasi)
            ->where('status_approval_bendahara', StatusApprovalBendahara::BelumDicek);
    }

    // Laporan hari ini tidak dihitung: hari ini mungkin masih ada transaksi
    public function laporanBelumDiajukan(): Builder
    {
        return LaporanHarian::whereIn('status', [StatusLaporanHarian::Draft, StatusLaporanHarian::Ditolak])
            ->where('tanggal', '<', today()->toDateString())
            ->whereHas('periodeLaporan', fn (Builder $q) => $q->where('status', '!=', StatusPeriodeLaporan::Ditutup));
    }

    public function laporanMenungguBendahara(): Builder
    {
        return LaporanHarian::where('status', StatusLaporanHarian::Diajukan);
    }

    public function refundBelumDikembalikan(): Builder
    {
        return PenyesuaianDana::whereIn('jenis_penyesuaian', [JenisPenyesuaian::KelebihanTransfer, JenisPenyesuaian::RefundBerhenti])
            ->where('status', StatusPenyesuaian::Proses);
    }

    // [periode terbuka yang sudah boleh diajukan, periode yang dikembalikan Bendahara (bila ada)]
    private function periodeSiapDiajukan(): array
    {
        $terbuka = PeriodeLaporan::where('status', StatusPeriodeLaporan::Terbuka)->orderBy('bulan')->get();

        return [
            $terbuka->filter(fn (PeriodeLaporan $p) => $this->laporan->hambatanAjukan($p) === [])->values(),
            $terbuka->first(fn (PeriodeLaporan $p) => filled($p->alasan_dikembalikan)),
        ];
    }

    private function uangMasuk(): Builder
    {
        return Transaksi::where('status', '!=', StatusTransaksi::Ditolak);
    }

    private function kartu(string $judul, int $jumlah, string $keterangan, string $href, string $nada = 'tugas'): array
    {
        return compact('judul', 'jumlah', 'keterangan', 'href', 'nada');
    }

    private function bulan(PeriodeLaporan $periode): string
    {
        return $periode->bulan->locale('id')->translatedFormat('F Y');
    }
}
