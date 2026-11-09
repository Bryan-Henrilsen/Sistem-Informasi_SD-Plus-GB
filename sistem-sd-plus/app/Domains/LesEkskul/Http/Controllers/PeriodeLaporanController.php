<?php

namespace App\Domains\LesEkskul\Http\Controllers;

use App\Domains\LesEkskul\Enums\StatusTagihan;
use App\Domains\LesEkskul\Enums\StatusTransaksi;
use App\Domains\LesEkskul\Models\PeriodeLaporan;
use App\Domains\LesEkskul\Models\Tagihan;
use App\Domains\LesEkskul\Models\Transaksi;
use App\Domains\LesEkskul\Services\PeriodeLaporanService;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PeriodeLaporanController extends Controller
{
    // Daftar periode beserta ringkasan tagihan bulannya dan uang yang masuk di periode itu
    public function index(PeriodeLaporanService $service): Response
    {
        $periode = PeriodeLaporan::orderByDesc('bulan')->get()->map(fn (PeriodeLaporan $p) => [
            'id' => $p->id,
            'bulan' => $p->bulan->toDateString(),
            'tanggal_mulai' => $p->tanggal_mulai->toDateString(),
            'tanggal_selesai' => $p->tanggal_selesai->toDateString(),
            'status' => $p->status,
            'tagihan' => $this->ringkasanTagihan($p),
            'uang_masuk' => (int) $this->transaksiPeriode($p)->sum('nominal'),
            'jumlah_transaksi' => $this->transaksiPeriode($p)->count(),
        ]);

        $usulan = $service->usulanBerikutnya();

        return Inertia::render('les-ekskul/periode/index', [
            'periode' => $periode,
            'usulan' => array_map(fn ($tanggal) => $tanggal->toDateString(), $usulan),
        ]);
    }

    public function store(Request $request, PeriodeLaporanService $service): RedirectResponse
    {
        $request->validate(['tanggal_selesai' => ['required', 'date']]);

        [$periode, $jumlah] = $service->buka($request->input('tanggal_selesai'));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Periode '.$periode->bulan->locale('id')->translatedFormat('F Y')." dibuka, {$jumlah} tagihan dibuat.",
        ]);

        return to_route('les-ekskul.periode.index');
    }

    // Tagihan bulan kegiatan yang sama dengan bulan periode (FR-9); terbayar = total alokasi
    private function ringkasanTagihan(PeriodeLaporan $periode): array
    {
        $tagihan = Tagihan::where('bulan_tagihan', $periode->bulan->toDateString())
            ->where('status', '!=', StatusTagihan::Dibatalkan)
            ->withSum('transaksi as terbayar', 'alokasi_transaksi.nominal')
            ->get();

        return [
            'jumlah' => $tagihan->count(),
            'lunas' => $tagihan->where('status', StatusTagihan::Lunas)->count(),
            'harus_dibayar' => $tagihan->sum('harus_dibayar'),
            'terbayar' => (int) $tagihan->sum('terbayar'),
        ];
    }

    // Uang yang dicatat di laporan harian periode ini, tanpa transfer yang ditolak
    private function transaksiPeriode(PeriodeLaporan $periode): Builder
    {
        return Transaksi::whereHas('laporanHarian', fn (Builder $q) => $q->where('periode_laporan_id', $periode->id))
            ->where('status', '!=', StatusTransaksi::Ditolak);
    }
}
