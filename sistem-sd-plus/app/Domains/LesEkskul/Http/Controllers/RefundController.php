<?php

namespace App\Domains\LesEkskul\Http\Controllers;

use App\Domains\LesEkskul\Enums\JenisPenyesuaian;
use App\Domains\LesEkskul\Enums\StatusPenyesuaian;
use App\Domains\LesEkskul\Models\PenyesuaianDana;
use App\Domains\LesEkskul\Services\PerubahanPendaftaranService;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

// Daftar refund (kelebihan transfer #22 dan berhenti/pindah #29) yang harus dikembalikan TU ke orang tua (FR-22)
class RefundController extends Controller
{
    public function index(Request $request): Response
    {
        $status = $request->string('status')->toString() === 'berhasil' ? StatusPenyesuaian::Berhasil : StatusPenyesuaian::Proses;

        $refund = PenyesuaianDana::query()
            ->whereIn('jenis_penyesuaian', [JenisPenyesuaian::KelebihanTransfer, JenisPenyesuaian::RefundBerhenti])
            ->where('status', $status)
            ->with(['pendaftaran.siswa', 'pendaftaran.lesTambahan.kelas', 'pendaftaran.ekstrakurikuler', 'transaksi', 'diprosesOlehUser'])
            ->latest('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (PenyesuaianDana $r) => [
                'id' => $r->id,
                'siswa' => $r->pendaftaran->siswa->nama,
                'kegiatan' => $r->pendaftaran->nama_kegiatan,
                'jenis' => $r->jenis_penyesuaian,
                'nominal' => $r->nominal,
                'keterangan' => $r->keterangan
                    ?? ($r->transaksi ? 'Kelebihan transfer tanggal '.$r->transaksi->tanggal->locale('id')->translatedFormat('j M Y') : null),
                'dicatat' => $r->created_at->toDateString(),
                'dikembalikan' => $status === StatusPenyesuaian::Berhasil ? $r->tanggal_proses->toDateString() : null,
                'diproses_oleh' => $r->diprosesOlehUser?->name,
            ]);

        return Inertia::render('les-ekskul/refund/index', [
            'refund' => $refund,
            'status' => $status,
            'totalBelum' => (int) PenyesuaianDana::whereIn('jenis_penyesuaian', [JenisPenyesuaian::KelebihanTransfer, JenisPenyesuaian::RefundBerhenti])
                ->where('status', StatusPenyesuaian::Proses)->sum('nominal'),
        ]);
    }

    public function tandai(Request $request, PenyesuaianDana $refund, PerubahanPendaftaranService $service): RedirectResponse
    {
        $service->tandaiRefundDikembalikan($refund, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Refund Rp'.number_format($refund->nominal, 0, ',', '.').' ditandai sudah dikembalikan.']);

        return back();
    }
}
