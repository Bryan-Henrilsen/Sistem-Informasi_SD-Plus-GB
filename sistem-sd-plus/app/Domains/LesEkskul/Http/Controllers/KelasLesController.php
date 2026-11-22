<?php

namespace App\Domains\LesEkskul\Http\Controllers;

use App\Domains\LesEkskul\Services\KelasLesService;
use App\Http\Controllers\Controller;
use App\Models\Kelas;
use App\Models\TahunAjaran;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

// Data master Kelas & Les Tambahan: TU mengubah, Kepala Sekolah & Bendahara melihat (Ringkasan v1.11 #43)
class KelasLesController extends Controller
{
    public function __construct(private KelasLesService $service) {}

    public function index(Request $request): Response
    {
        $tahunAjaran = $request->integer('tahun')
            ? TahunAjaran::findOrFail($request->integer('tahun'))
            : TahunAjaran::aktif()->firstOrFail();
        $bisaUbah = $request->user()->hasRole('tu');

        return Inertia::render('les-ekskul/kelas/index', [
            'tahunAjaran' => ['id' => $tahunAjaran->id, 'nama' => $tahunAjaran->nama],
            'daftarTahun' => TahunAjaran::orderByDesc('tanggal_mulai')->get(['id', 'nama'])->map(fn (TahunAjaran $t) => ['id' => $t->id, 'nama' => $t->nama]),
            'kelas' => $this->service->daftar($tahunAjaran),
            'guru' => $bisaUbah ? $this->service->guruPilihan($tahunAjaran) : [],
            'sumberSalinan' => $bisaUbah ? $this->service->sumberSalinan($tahunAjaran)?->only(['id', 'nama']) : null,
            'bisaUbah' => $bisaUbah,
        ]);
    }

    public function store(Request $request, TahunAjaran $tahunAjaran): RedirectResponse
    {
        $kelas = $this->service->simpan($tahunAjaran, $this->validasi($request));

        return $this->kembali("Kelas {$kelas->nama_lengkap} ditambahkan.");
    }

    public function update(Request $request, Kelas $kelas): RedirectResponse
    {
        $biayaLama = $kelas->lesTambahan?->biaya;
        $kelas = $this->service->simpan($kelas->tahunAjaran, $this->validasi($request), $kelas);
        $biayaBaru = $kelas->lesTambahan()->value('biaya');
        $catatan = $biayaLama !== null && $biayaBaru !== null && (int) $biayaBaru !== $biayaLama
            ? ' Biaya les baru berlaku untuk tagihan yang dibuat sesudah ini.'
            : '';

        return $this->kembali("Kelas {$kelas->nama_lengkap} disimpan.{$catatan}");
    }

    public function destroy(Kelas $kelas): RedirectResponse
    {
        $nama = $kelas->nama_lengkap;
        $this->service->hapus($kelas);

        return $this->kembali("Kelas {$nama} dihapus.");
    }

    public function samakanBiaya(Request $request, TahunAjaran $tahunAjaran): RedirectResponse
    {
        $data = $request->validate([
            'jenjang' => ['required', 'integer', 'between:1,6'],
            'biaya' => ['required', 'integer', 'min:1'],
        ], ['biaya.required' => 'Isi biaya les.', 'biaya.min' => 'Biaya les harus lebih dari 0.']);

        $jumlah = $this->service->samakanBiaya($tahunAjaran, $data['jenjang'], $data['biaya']);

        return $this->kembali("Biaya les {$jumlah} kelas jenjang {$data['jenjang']} disamakan. Berlaku untuk tagihan yang dibuat sesudah ini.");
    }

    public function salin(TahunAjaran $tahunAjaran): RedirectResponse
    {
        $jumlah = $this->service->salinDariTahunLalu($tahunAjaran);

        return $this->kembali("{$jumlah} kelas disalin. Isi wali kelas tiap kelas untuk tahun ajaran ini.");
    }

    private function validasi(Request $request): array
    {
        return $request->validate([
            'jenjang' => ['required', 'integer', 'between:1,6'],
            'nama_kelas' => ['required', 'string', 'max:10'],
            'nama_pelindung' => ['nullable', 'string', 'max:100'],
            'wali_kelas_id' => ['nullable', 'integer', 'exists:guru,id'],
            'ada_les' => ['boolean'],
            'biaya_les' => ['nullable', 'required_if_accepted:ada_les', 'integer', 'min:1'],
        ], [
            'nama_kelas.required' => 'Nama kelas wajib diisi, mis. 2A.',
            'nama_kelas.max' => 'Nama kelas paling panjang 10 karakter, mis. 2A.',
            'biaya_les.required_if_accepted' => 'Isi biaya les per bulan.',
            'biaya_les.min' => 'Biaya les harus lebih dari 0.',
        ]);
    }

    private function kembali(string $pesan): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $pesan]);

        return back();
    }
}
