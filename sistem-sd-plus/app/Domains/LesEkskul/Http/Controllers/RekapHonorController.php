<?php

namespace App\Domains\LesEkskul\Http\Controllers;

use App\Domains\LesEkskul\Models\HonorFlatJenis;
use App\Domains\LesEkskul\Models\PeriodeLaporan;
use App\Domains\LesEkskul\Services\HonorService;
use App\Domains\LesEkskul\Services\PengaturanHonorService;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

// Rekap honor per periode (FR-16–FR-20, FR-25b; Ringkasan v1.11 #33–#34)
class RekapHonorController extends Controller
{
    public function __construct(private HonorService $service) {}

    public function index(Request $request): Response
    {
        $daftarPeriode = PeriodeLaporan::orderByDesc('bulan')->get();
        // Default: periode terakhir yang bukunya sudah ditutup (honor hanya ada setelah tutup buku)
        $periode = $daftarPeriode->firstWhere('id', $request->integer('periode'))
            ?? $daftarPeriode->first(fn (PeriodeLaporan $p) => $p->sudahDitutup())
            ?? $daftarPeriode->first();
        $user = $request->user();

        return Inertia::render('les-ekskul/honor/rekap', [
            'daftarPeriode' => $daftarPeriode->map(fn (PeriodeLaporan $p) => ['id' => $p->id, 'bulan' => $p->bulan->toDateString(), 'status' => $p->status]),
            'periode' => $periode ? [
                'id' => $periode->id,
                'bulan' => $periode->bulan->toDateString(),
                'tanggal_mulai' => $periode->tanggal_mulai->toDateString(),
                'tanggal_selesai' => $periode->tanggal_selesai->toDateString(),
                'status' => $periode->status,
                'diketahui_oleh' => $periode->honorDiketahuiOlehUser?->name,
                'diketahui_at' => $periode->honor_diketahui_at?->toIso8601String(),
            ] : null,
            'sudahDihitung' => $sudah = $periode ? $this->service->sudahDihitung($periode) : false,
            // Buku ditutup tetapi tidak ada pendapatan maupun honor flat: tidak ada yang dihitung, cukup ditandai Sudah Diketahui
            'tanpaHonor' => $periode && $periode->sudahDitutup() && ! $sudah && ! $this->service->adaYangDihitung($periode),
            // Hambatan hanya relevan bila honor masih boleh dihitung (buku ditutup & belum diketahui)
            'hambatan' => $periode && $periode->sudahDitutup() && $periode->honor_diketahui_at === null ? $this->service->hambatan($periode) : [],
            'rekap' => $periode ? $this->service->rekap($periode) : null,
            'bisaHitung' => $user->hasAnyRole(['bendahara', 'kepala_sekolah']),
            'bisaTandai' => $user->hasRole('kepala_sekolah'),
            // Honor flat periode ini, diatur Kepala Sekolah setelah honor dihitung (#39)
            'honorFlat' => $periode && $sudah ? $this->service->honorFlatPeriode($periode) : [],
            'bisaAturFlat' => $user->hasRole('kepala_sekolah') && $sudah && $periode->honor_diketahui_at === null,
            'orang' => $user->hasRole('kepala_sekolah') ? app(PengaturanHonorService::class)->orangPenerima() : [],
        ]);
    }

    // Kepala Sekolah: satu jenis honor flat untuk periode ini — penerima & jumlah, atau kosong = tidak dibayar (#39)
    public function aturFlat(Request $request, PeriodeLaporan $periode, HonorFlatJenis $honorFlatJenis): RedirectResponse
    {
        $data = $request->validate([
            'penerima' => ['array'],
            'penerima.*.user_id' => ['required', 'integer', 'distinct', 'exists:users,id'],
            'penerima.*.jumlah' => ['required', 'integer', 'min:1'],
        ]);

        $penerima = collect($data['penerima'] ?? [])->mapWithKeys(fn (array $p) => [$p['user_id'] => $p['jumlah']])->all();
        $this->service->aturHonorFlatPeriode($periode, $honorFlatJenis, $penerima);

        return $this->kembali($penerima === []
            ? "Honor {$honorFlatJenis->nama} tidak dibayar di periode {$this->bulan($periode)}."
            : "Honor {$honorFlatJenis->nama} periode {$this->bulan($periode)} disimpan untuk ".count($penerima).' orang.');
    }

    public function hitung(PeriodeLaporan $periode): RedirectResponse
    {
        $hasil = $this->service->hitung($periode);

        return $this->kembali("Honor {$this->bulan($periode)} dihitung: {$hasil['kegiatan']} kegiatan.");
    }

    public function tandaiDiketahui(Request $request, PeriodeLaporan $periode): RedirectResponse
    {
        $this->service->tandaiDiketahui($periode, $request->user());

        return $this->kembali("Honor {$this->bulan($periode)} ditandai Sudah Diketahui dan terkunci.");
    }

    private function kembali(string $pesan): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $pesan]);

        return back();
    }

    private function bulan(PeriodeLaporan $periode): string
    {
        return $periode->bulan->locale('id')->translatedFormat('F Y');
    }
}
