<?php

namespace App\Domains\LesEkskul\Http\Controllers;

use App\Domains\LesEkskul\Models\KonfirmasiTransfer;
use App\Domains\LesEkskul\Services\KonfirmasiTransferService;
use App\Domains\LesEkskul\Services\TagihanOrangTuaService;
use App\Http\Controllers\Controller;
use App\Models\Siswa;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

// Konfirmasi "Saya sudah bayar" untuk transfer manual (FR-15b, Ringkasan v1.11 #41):
// orang tua mengajukan/membatalkan, TU menolak (mencatatnya lewat Catat Pembayaran di TransaksiController)
class KonfirmasiTransferController extends Controller
{
    public function __construct(private KonfirmasiTransferService $service) {}

    // Halaman Pembayaran: total pas, rekening sekolah, usulan berita transfer
    public function bayar(Request $request, TagihanOrangTuaService $tagihan): Response
    {
        $data = $request->validate([
            'siswa' => ['required', 'integer'],
            'tagihan' => ['required', 'array', 'min:1'],
            'tagihan.*' => ['integer'],
        ], ['tagihan.required' => 'Centang minimal satu tagihan yang akan dibayar.']);

        $siswa = $request->user()->anak()->with('kelasTahunAktif.kelas')->findOrFail($data['siswa']); // anak orang lain = 404

        return Inertia::render('les-ekskul/orang-tua/bayar', [
            'siswa' => ['id' => $siswa->id, 'nama' => $siswa->nama, 'kelas' => $siswa->kelasTahunAktif?->kelas?->nama_lengkap],
            ...$this->service->rincian($siswa, $data['tagihan']),
            'rekening' => $tagihan->rekening(),
            'adaWhatsApp' => $this->service->tautanWhatsApp('') !== null,
            'hariIni' => today()->toDateString(),
        ]);
    }

    public function ajukan(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'siswa_id' => ['required', 'integer', 'exists:siswa,id'],
            'tagihan_ids' => ['required', 'array', 'min:1'],
            'tagihan_ids.*' => ['integer'],
            'tanggal_transfer' => ['required', 'date', 'before_or_equal:today', 'after_or_equal:'.today()->subDays(60)->toDateString()],
            'nama_pengirim' => ['nullable', 'string', 'max:100'],
        ], [
            'tanggal_transfer.before_or_equal' => 'Tanggal transfer tidak boleh di masa depan.',
            'tanggal_transfer.after_or_equal' => 'Tanggal transfer paling lama 60 hari yang lalu. Hubungi TU untuk transfer yang lebih lama.',
        ]);

        $this->service->ajukan($request->user(), Siswa::findOrFail($data['siswa_id']), $data['tagihan_ids'], $data['tanggal_transfer'], $data['nama_pengirim'] ?? null);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Terima kasih. Kirim bukti transfer ke TU lewat WhatsApp agar segera diverifikasi.']);

        return to_route('ortu.tagihan');
    }

    public function batalkan(Request $request, KonfirmasiTransfer $konfirmasiTransfer): RedirectResponse
    {
        $this->service->batalkan($request->user(), $konfirmasiTransfer);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Konfirmasi dibatalkan. Tagihannya bisa dipilih lagi.']);

        return back();
    }

    // TU: transfer tidak ditemukan di mutasi, dsb.; alasan wajib dan ditampilkan ke orang tua
    public function tolak(Request $request, KonfirmasiTransfer $konfirmasiTransfer): RedirectResponse
    {
        $data = $request->validate(['alasan' => ['required', 'string', 'max:500']], ['alasan.required' => 'Alasan penolakan wajib diisi.']);

        $this->service->tolak($konfirmasiTransfer, $request->user(), $data['alasan']);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Konfirmasi ditolak. Orang tua melihat alasannya di halaman Tagihan.']);

        return back();
    }
}
