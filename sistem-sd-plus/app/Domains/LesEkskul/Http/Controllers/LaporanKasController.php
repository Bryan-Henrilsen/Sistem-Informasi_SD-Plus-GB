<?php

namespace App\Domains\LesEkskul\Http\Controllers;

use App\Domains\LesEkskul\Enums\KategoriKas;
use App\Domains\LesEkskul\Models\KasManual;
use App\Domains\LesEkskul\Models\PeriodeLaporan;
use App\Domains\LesEkskul\Services\LaporanKasService;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

// Laporan Keuangan Kas per periode (A6 #28, Ringkasan v1.11 #35)
class LaporanKasController extends Controller
{
    public function __construct(private LaporanKasService $service) {}

    public function index(Request $request): Response
    {
        $daftarPeriode = PeriodeLaporan::orderByDesc('bulan')->get();
        // Default: periode terakhir yang bukunya sudah ditutup
        $periode = $daftarPeriode->firstWhere('id', $request->integer('periode'))
            ?? $daftarPeriode->first(fn (PeriodeLaporan $p) => $p->sudahDitutup())
            ?? $daftarPeriode->first();

        return Inertia::render('les-ekskul/laporan-kas', [
            'daftarPeriode' => $daftarPeriode->map(fn (PeriodeLaporan $p) => ['id' => $p->id, 'bulan' => $p->bulan->toDateString(), 'status' => $p->status]),
            'periode' => $periode ? [
                'id' => $periode->id,
                'bulan' => $periode->bulan->toDateString(),
                'tanggal_mulai' => $periode->tanggal_mulai->toDateString(),
                'tanggal_selesai' => $periode->tanggal_selesai->toDateString(),
                'status' => $periode->status,
                'pertama' => ! PeriodeLaporan::where('bulan', '<', $periode->bulan->toDateString())->exists(),
            ] : null,
            'laporan' => $periode ? $this->service->susun($periode) : null,
            'kategori' => collect(KategoriKas::cases())->map(fn (KategoriKas $k) => [
                'value' => $k->value,
                'label' => $k->label(),
                'masuk' => $k->masuk(),
                'saldo_awal' => $k->saldoAwal(),
                'butuh_uraian' => $k->butuhUraian(),
            ]),
            'bisaCatat' => $request->user()->hasAnyRole(['bendahara', 'kepala_sekolah']),
        ]);
    }

    public function store(Request $request, PeriodeLaporan $periode): RedirectResponse
    {
        $data = $request->validate([
            'kategori' => ['required', Rule::enum(KategoriKas::class)],
            'nominal' => ['required', 'integer', 'min:1'],
            'tanggal' => ['required', 'date'],
            'uraian' => ['nullable', 'string', 'max:255'],
        ], [
            'kategori.required' => 'Pilih kategori.',
            'nominal.required' => 'Nominal wajib diisi.',
            'nominal.min' => 'Nominal harus lebih dari 0.',
            'tanggal.required' => 'Tanggal wajib diisi.',
        ]);

        $kategori = KategoriKas::from($data['kategori']);
        $this->service->catat($periode, $kategori, (int) $data['nominal'], $data['tanggal'], $data['uraian'] ?? null, $request->user());

        return $this->kembali("{$kategori->label()} dicatat.");
    }

    public function destroy(KasManual $kasManual): RedirectResponse
    {
        $this->service->hapus($kasManual);

        return $this->kembali("{$kasManual->kategori->label()} dihapus.");
    }

    private function kembali(string $pesan): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $pesan]);

        return back();
    }
}
