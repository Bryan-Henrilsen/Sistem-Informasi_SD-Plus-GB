<?php

namespace App\Domains\LesEkskul\Http\Controllers;

use App\Domains\LesEkskul\Enums\MetodeTransaksi;
use App\Domains\LesEkskul\Enums\StatusLaporanHarian;
use App\Domains\LesEkskul\Enums\StatusPeriodeLaporan;
use App\Domains\LesEkskul\Enums\StatusTransaksi;
use App\Domains\LesEkskul\Models\LaporanHarian;
use App\Domains\LesEkskul\Models\PeriodeLaporan;
use App\Domains\LesEkskul\Models\Transaksi;
use App\Domains\LesEkskul\Services\LaporanService;
use App\Domains\LesEkskul\Services\RingkasanTugasService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

// Laporan harian (FR-12) dan laporan bulanan / tutup buku per periode (FR-13, FR-14, FR-14b)
class LaporanController extends Controller
{
    public function __construct(private LaporanService $service, private RingkasanTugasService $ringkasan) {}

    public function harian(Request $request): Response
    {
        $daftarPeriode = PeriodeLaporan::orderByDesc('bulan')->get();
        $dipilih = $daftarPeriode->firstWhere('id', $request->integer('periode'))
            ?? PeriodeLaporan::mencakup(today())->first()
            ?? $daftarPeriode->first();
        $tindakan = $request->boolean('tindakan');

        return Inertia::render('les-ekskul/laporan-harian/index', [
            'daftarPeriode' => $daftarPeriode->map(fn (PeriodeLaporan $p) => ['id' => $p->id, 'bulan' => $p->bulan->toDateString(), 'status' => $p->status]),
            'periode' => $dipilih ? $this->ringkasPeriode($dipilih) : null,
            'laporan' => match (true) {
                $tindakan => $this->muat($this->perluTindakan($request->user()), 'asc'),
                $dipilih !== null => $this->laporanHarian($dipilih, 'desc'),
                default => [],
            },
            'tindakan' => $tindakan,
            'menunggu' => LaporanHarian::where('status', StatusLaporanHarian::Diajukan)->count(),
            'bisaAjukanSemua' => $request->user()->hasRole('tu') ? $this->ringkasan->laporanBelumDiajukan()->count() : 0,
        ]);
    }

    // TU: ajukan semua laporan draft/ditolak sampai kemarin (#32)
    public function ajukanSemuaHarian(Request $request): RedirectResponse
    {
        $jumlah = $this->service->ajukanSemuaSampaiKemarin($request->user());

        return $this->kembali("{$jumlah} laporan harian diajukan ke Bendahara.");
    }

    // Bendahara: setujui sekaligus transfer yang sudah diverifikasi TU di laporan ini (#32)
    public function setujuiTransferHarian(Request $request, LaporanHarian $laporanHarian): RedirectResponse
    {
        $jumlah = $this->service->setujuiSemuaTransfer($laporanHarian, $request->user());

        return $this->kembali("{$jumlah} transfer {$this->tanggal($laporanHarian)} disetujui."
            .$this->sisa($this->ringkasan->transferMenungguBendahara()->count(), 'transfer menunggu persetujuan'));
    }

    // Mode "perlu tindakan saya" (dibuka dari beranda/badge), lintas periode yang belum ditutup:
    // TU = laporan draft/ditolak atau berisi transfer yang perlu diverifikasi;
    // Bendahara = laporan yang diajukan atau berisi transfer yang perlu disetujui
    private function perluTindakan(User $user): Builder
    {
        $tu = $user->hasRole('tu');
        $bendahara = $user->hasRole('bendahara');

        return LaporanHarian::query()
            ->whereHas('periodeLaporan', fn (Builder $q) => $q->where('status', '!=', StatusPeriodeLaporan::Ditutup))
            ->where(function (Builder $q) use ($tu, $bendahara) {
                $q->whereRaw('1 = 0'); // tanpa role TU/Bendahara: tidak ada yang perlu ditindak

                if ($tu) {
                    $q->orWhereIn('status', [StatusLaporanHarian::Draft, StatusLaporanHarian::Ditolak])
                        ->orWhereHas('transaksi', fn (Builder $t) => $t->whereIn('id', $this->ringkasan->transferMenungguVerifikasi()->select('id')));
                }

                if ($bendahara) {
                    $q->orWhere('status', StatusLaporanHarian::Diajukan)
                        ->orWhereHas('transaksi', fn (Builder $t) => $t->whereIn('id', $this->ringkasan->transferMenungguBendahara()->select('id')));
                }
            });
    }

    private function sisa(int $jumlah, string $apa): string
    {
        return $jumlah > 0 ? " Sisa {$jumlah} {$apa}." : ' Tidak ada lagi yang menunggu.';
    }

    // Laporan bulanan: rekap per hari yang bisa dibuka per transaksi; total dihitung, tidak bisa diedit (FR-13)
    public function bulanan(PeriodeLaporan $periode): Response
    {
        $laporan = $this->laporanHarian($periode, 'asc');

        return Inertia::render('les-ekskul/periode/show', [
            'periode' => $this->ringkasPeriode($periode) + [
                'diajukan_oleh' => $periode->diajukanOlehUser?->name,
                'diajukan_at' => $periode->diajukan_at?->toIso8601String(),
                'alasan_dikembalikan' => $periode->status === StatusPeriodeLaporan::Terbuka ? $periode->alasan_dikembalikan : null,
                'dikembalikan_oleh' => $periode->dikembalikanOlehUser?->name,
                'ditutup_oleh' => $periode->ditutupOlehUser?->name,
                'ditutup_at' => $periode->ditutup_at?->toIso8601String(),
            ],
            'laporan' => $laporan,
            'ringkasan' => [
                'jumlah_transaksi' => $laporan->sum('jumlah'),
                'total' => $laporan->sum('total'),
                'total_cash' => $laporan->sum('total_cash'),
                'total_transfer' => $laporan->sum('total_transfer'),
                'laporan_belum_disetujui' => $laporan->where('status', '!=', StatusLaporanHarian::Disetujui)->count(),
                'transfer_belum_selesai' => $this->service->transferBelumSelesai($periode)->count(),
                // Dasar honor periode ini, terisi setelah tutup buku (Catatan v1.8 #3)
                'diakui_honor' => (int) DB::table('alokasi_transaksi')->where('diakui_periode_id', $periode->id)->sum('nominal'),
            ],
            'hambatanAjukan' => $this->service->hambatanAjukan($periode),
            'hambatanTutupBuku' => $this->service->hambatanTutupBuku($periode),
        ]);
    }

    public function ajukanHarian(Request $request, LaporanHarian $laporanHarian): RedirectResponse
    {
        $this->service->ajukanHarian($laporanHarian, $request->user());

        return $this->kembali('Laporan harian '.$this->tanggal($laporanHarian).' diajukan ke Bendahara.');
    }

    public function tarikHarian(LaporanHarian $laporanHarian): RedirectResponse
    {
        $this->service->tarikHarian($laporanHarian);

        return $this->kembali('Laporan harian '.$this->tanggal($laporanHarian).' ditarik kembali menjadi draft.');
    }

    public function setujuiHarian(Request $request, LaporanHarian $laporanHarian): RedirectResponse
    {
        $this->service->setujuiHarian($laporanHarian, $request->user());

        return $this->kembali('Laporan harian '.$this->tanggal($laporanHarian).' disetujui.'
            .$this->sisa($this->ringkasan->laporanMenungguBendahara()->count(), 'laporan harian menunggu persetujuan'));
    }

    public function tolakHarian(Request $request, LaporanHarian $laporanHarian): RedirectResponse
    {
        $data = $request->validate(['alasan' => ['required', 'string', 'max:500']], ['alasan.required' => 'Alasan penolakan wajib diisi.']);
        $this->service->tolakHarian($laporanHarian, $request->user(), $data['alasan']);

        return $this->kembali('Laporan harian '.$this->tanggal($laporanHarian).' ditolak dan dikembalikan ke TU.');
    }

    public function ajukanBulanan(Request $request, PeriodeLaporan $periode): RedirectResponse
    {
        $this->service->ajukanBulanan($periode, $request->user());

        return $this->kembali('Laporan bulanan '.$this->bulan($periode).' diajukan ke Bendahara.');
    }

    public function tarikBulanan(PeriodeLaporan $periode): RedirectResponse
    {
        $this->service->tarikBulanan($periode);

        return $this->kembali('Laporan bulanan '.$this->bulan($periode).' ditarik kembali.');
    }

    public function kembalikanBulanan(Request $request, PeriodeLaporan $periode): RedirectResponse
    {
        $data = $request->validate(['alasan' => ['required', 'string', 'max:500']], ['alasan.required' => 'Alasan wajib diisi.']);
        $this->service->kembalikanBulanan($periode, $request->user(), $data['alasan']);

        return $this->kembali('Laporan bulanan '.$this->bulan($periode).' dikembalikan ke TU.');
    }

    public function tutupBuku(Request $request, PeriodeLaporan $periode): RedirectResponse
    {
        $diakui = $this->service->tutupBuku($periode, $request->user());

        return $this->kembali('Buku periode '.$this->bulan($periode)." ditutup. {$diakui} alokasi pembayaran diakui untuk honor periode ini.");
    }

    private function ringkasPeriode(PeriodeLaporan $periode): array
    {
        return [
            'id' => $periode->id,
            'bulan' => $periode->bulan->toDateString(),
            'tanggal_mulai' => $periode->tanggal_mulai->toDateString(),
            'tanggal_selesai' => $periode->tanggal_selesai->toDateString(),
            'status' => $periode->status,
        ];
    }

    private function laporanHarian(PeriodeLaporan $periode, string $urutan): Collection
    {
        return $this->muat($periode->laporanHarian()->getQuery(), $urutan);
    }

    private function muat(Builder $query, string $urutan): Collection
    {
        return $query
            ->with(['transaksi' => fn ($q) => $q->with('siswa')->orderBy('id'), 'diajukanOlehUser', 'disetujuiOlehUser', 'ditolakOlehUser'])
            ->orderBy('tanggal', $urutan)
            ->get()
            ->map(fn (LaporanHarian $l) => $this->barisHarian($l));
    }

    // Total tanpa transfer yang ditolak; transaksi dicatat terlambat bila tanggal uang masuk < tanggal laporan (A6 #20)
    private function barisHarian(LaporanHarian $laporan): array
    {
        $sah = $laporan->transaksi->where('status', '!=', StatusTransaksi::Ditolak);

        return [
            'id' => $laporan->id,
            'tanggal' => $laporan->tanggal->toDateString(),
            'status' => $laporan->status,
            'jumlah' => $sah->count(),
            'total' => (int) $sah->sum('nominal'),
            'total_cash' => (int) $sah->where('metode', MetodeTransaksi::Cash)->sum('nominal'),
            'total_transfer' => (int) $sah->where('metode', '!=', MetodeTransaksi::Cash)->sum('nominal'),
            'diajukan_oleh' => $laporan->diajukanOlehUser?->name,
            'disetujui_oleh' => $laporan->disetujuiOlehUser?->name,
            'alasan_ditolak' => $laporan->status === StatusLaporanHarian::Ditolak ? $laporan->alasan_ditolak : null,
            'ditolak_oleh' => $laporan->status === StatusLaporanHarian::Ditolak ? $laporan->ditolakOlehUser?->name : null,
            'transaksi' => $laporan->transaksi->map(fn (Transaksi $t) => [
                'id' => $t->id,
                'siswa' => $t->siswa->nama,
                'tanggal' => $t->tanggal->toDateString(),
                'terlambat' => $t->tanggal->lt($laporan->tanggal),
                'metode' => $t->metode,
                'nominal' => $t->nominal,
                'status' => $t->status,
                'status_approval_bendahara' => $t->status_approval_bendahara,
                'sumber_input' => $t->sumber_input,
            ])->values(),
        ];
    }

    private function kembali(string $pesan): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $pesan]);

        return back();
    }

    private function tanggal(LaporanHarian $laporan): string
    {
        return $laporan->tanggal->locale('id')->translatedFormat('j F Y');
    }

    private function bulan(PeriodeLaporan $periode): string
    {
        return $periode->bulan->locale('id')->translatedFormat('F Y');
    }
}
