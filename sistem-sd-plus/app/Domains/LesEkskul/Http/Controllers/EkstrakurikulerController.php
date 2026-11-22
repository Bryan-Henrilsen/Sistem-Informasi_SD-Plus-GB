<?php

namespace App\Domains\LesEkskul\Http\Controllers;

use App\Domains\LesEkskul\Http\Requests\SimpanEkstrakurikulerRequest;
use App\Domains\LesEkskul\Models\Ekstrakurikuler;
use App\Domains\LesEkskul\Services\EkstrakurikulerService;
use App\Http\Controllers\Controller;
use App\Models\TahunAjaran;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

// Data master Kegiatan Ekstrakurikuler: TU mengubah, Kepala Sekolah & Bendahara melihat (Ringkasan v1.11 #42)
class EkstrakurikulerController extends Controller
{
    public function __construct(private EkstrakurikulerService $service) {}

    public function index(Request $request): Response
    {
        $tahunAjaran = $request->integer('tahun')
            ? TahunAjaran::findOrFail($request->integer('tahun'))
            : TahunAjaran::aktif()->firstOrFail();
        $bisaUbah = $request->user()->hasRole('tu');

        return Inertia::render('les-ekskul/ekstrakurikuler/index', [
            'tahunAjaran' => ['id' => $tahunAjaran->id, 'nama' => $tahunAjaran->nama],
            'daftarTahun' => TahunAjaran::orderByDesc('tanggal_mulai')->get(['id', 'nama'])->map(fn (TahunAjaran $t) => ['id' => $t->id, 'nama' => $t->nama]),
            'kegiatan' => $this->service->daftar($tahunAjaran),
            'sumberSalinan' => $bisaUbah ? $this->service->sumberSalinan($tahunAjaran)?->only(['id', 'nama']) : null,
            'bisaUbah' => $bisaUbah,
        ]);
    }

    public function store(SimpanEkstrakurikulerRequest $request, TahunAjaran $tahunAjaran): RedirectResponse
    {
        $kegiatan = $this->service->simpan($tahunAjaran, $request->validated());

        return $this->kembali("{$kegiatan->nama} ditambahkan.");
    }

    public function update(SimpanEkstrakurikulerRequest $request, Ekstrakurikuler $ekstrakurikuler): RedirectResponse
    {
        abort_if($ekstrakurikuler->adalahKelasParalel(), 404); // kelas paralel diubah lewat kegiatan induknya

        $biayaLama = $ekstrakurikuler->biaya;
        $kegiatan = $this->service->simpan($ekstrakurikuler->tahunAjaran, $request->validated(), $ekstrakurikuler);
        $catatanBiaya = $kegiatan->biaya !== $biayaLama
            ? ' Biaya baru berlaku untuk tagihan yang dibuat sesudah ini; tagihan yang sudah ada tidak berubah.'
            : '';

        return $this->kembali("{$kegiatan->nama} disimpan.{$catatanBiaya}");
    }

    public function destroy(Ekstrakurikuler $ekstrakurikuler): RedirectResponse
    {
        $nama = $ekstrakurikuler->nama;
        $this->service->hapus($ekstrakurikuler);

        return $this->kembali("{$nama} dihapus.");
    }

    public function salin(TahunAjaran $tahunAjaran): RedirectResponse
    {
        $jumlah = $this->service->salinDariTahunLalu($tahunAjaran);

        return $this->kembali("{$jumlah} kegiatan disalin. Periksa biaya, kuota, dan jadwalnya untuk tahun ajaran ini.");
    }

    private function kembali(string $pesan): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $pesan]);

        return back();
    }
}
