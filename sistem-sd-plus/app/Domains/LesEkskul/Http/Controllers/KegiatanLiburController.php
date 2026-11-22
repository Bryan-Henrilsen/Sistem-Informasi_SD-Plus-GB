<?php

namespace App\Domains\LesEkskul\Http\Controllers;

use App\Domains\LesEkskul\Models\KegiatanLibur;
use App\Domains\LesEkskul\Services\KegiatanLiburService;
use App\Http\Controllers\Controller;
use App\Models\TahunAjaran;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

// Kegiatan tidak berlangsung (FR-23, Ringkasan v1.11 #48): TU menandai; Bendahara & Kepala Sekolah melihat
class KegiatanLiburController extends Controller
{
    public function __construct(private KegiatanLiburService $service) {}

    public function index(Request $request): Response
    {
        $tahunAjaran = TahunAjaran::aktif()->first();

        return Inertia::render('les-ekskul/kegiatan-libur', [
            'tahunAjaran' => $tahunAjaran?->only('id', 'nama'),
            'libur' => $tahunAjaran ? $this->service->daftar($tahunAjaran) : [],
            'pilihanKegiatan' => $tahunAjaran ? $this->service->pilihanKegiatan($tahunAjaran) : [],
            'pilihanBulan' => $tahunAjaran ? $this->service->pilihanBulan($tahunAjaran) : [],
            'bisaUbah' => $request->user()->hasRole('tu'),
        ]);
    }

    // Hitung dampak tanpa menyimpan; hasilnya dikirim lewat flash lalu ditampilkan di dialog
    public function pratinjau(Request $request): RedirectResponse
    {
        Inertia::flash('pratinjau', $this->service->pratinjau($this->validasi($request, false), $request->user()));

        return back();
    }

    public function store(Request $request): RedirectResponse
    {
        $libur = $this->service->tandai($this->validasi($request, true), $request->user());

        $pesan = "{$libur->nama_kegiatan} ditandai tidak berlangsung {$libur->bulan->locale('id')->translatedFormat('F Y')}: {$libur->jumlah_tagihan} tagihan dibatalkan.";
        if ($libur->nominal_refund > 0) {
            $pesan .= ' Ada refund yang perlu dikembalikan di halaman Refund.';
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $pesan]);

        return back();
    }

    public function destroy(KegiatanLibur $kegiatanLibur): RedirectResponse
    {
        $this->service->batalkan($kegiatanLibur);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Penandaan dibatalkan; tagihan bulan itu ditagih kembali.']);

        return back();
    }

    private function validasi(Request $request, bool $alasanWajib): array
    {
        return $request->validate([
            'kegiatan' => ['required', 'string', 'max:30'],
            'bulan' => ['required', 'date_format:Y-m'],
            'alasan' => [$alasanWajib ? 'required' : 'nullable', 'string', 'max:500'],
        ], [
            'kegiatan.required' => 'Pilih kegiatan.',
            'bulan.required' => 'Pilih bulan.',
            'alasan.required' => 'Alasan wajib diisi, mis. pelatih berhalangan sebulan.',
        ]);
    }
}
