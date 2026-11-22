<?php

namespace App\Domains\LesEkskul\Http\Controllers;

use App\Domains\LesEkskul\Services\KenaikanKelasService;
use App\Domains\LesEkskul\Services\TahunAjaranService;
use App\Http\Controllers\Controller;
use App\Models\TahunAjaran;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

// Tahun Ajaran & Semester (Kepala Sekolah mengubah) dan kenaikan kelas massal (TU & Kepala Sekolah) — Ringkasan v1.11 #45
class TahunAjaranController extends Controller
{
    public function __construct(private TahunAjaranService $service, private KenaikanKelasService $kenaikan) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $aktif = TahunAjaran::aktif()->first();
        // Tahun ajaran sesudah tahun aktif: tujuan kenaikan kelas & calon tahun aktif berikutnya
        $berikutnya = $aktif ? TahunAjaran::where('tanggal_mulai', '>', $aktif->tanggal_mulai->toDateString())->orderBy('tanggal_mulai')->first() : null;

        return Inertia::render('les-ekskul/tahun-ajaran/index', [
            'tahunAjaran' => $this->service->daftar(),
            'usulan' => $this->service->usulanBaru(),
            'berikutnya' => $berikutnya ? [
                'id' => $berikutnya->id,
                'nama' => $berikutnya->nama,
                ...$this->service->persiapan($berikutnya),
            ] : null,
            'bisaUbah' => $user->hasRole('kepala_sekolah'),
            'bisaNaikkan' => $user->hasAnyRole(['tu', 'kepala_sekolah']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $tahunAjaran = $this->service->simpan($this->validasi($request));

        return $this->kembali("Tahun ajaran {$tahunAjaran->nama} dibuat. Siapkan kelas dan kegiatannya, lalu aktifkan.");
    }

    public function update(Request $request, TahunAjaran $tahunAjaran): RedirectResponse
    {
        $this->service->simpan($this->validasi($request), $tahunAjaran);

        return $this->kembali("Tahun ajaran {$tahunAjaran->nama} disimpan.");
    }

    public function aktifkan(TahunAjaran $tahunAjaran): RedirectResponse
    {
        $this->service->aktifkan($tahunAjaran);

        return $this->kembali("Tahun ajaran {$tahunAjaran->nama} sekarang aktif.");
    }

    public function destroy(TahunAjaran $tahunAjaran): RedirectResponse
    {
        $nama = $tahunAjaran->nama;
        $this->service->hapus($tahunAjaran);

        return $this->kembali("Tahun ajaran {$nama} dihapus.");
    }

    // Halaman kenaikan kelas massal ke $tahunAjaran (tujuan)
    public function kenaikan(TahunAjaran $tahunAjaran): Response
    {
        $asal = $this->kenaikan->asal($tahunAjaran);

        return Inertia::render('les-ekskul/tahun-ajaran/kenaikan', [
            'tujuan' => ['id' => $tahunAjaran->id, 'nama' => $tahunAjaran->nama],
            'asal' => $asal ? ['id' => $asal->id, 'nama' => $asal->nama] : null,
            'kelasAsal' => $asal ? $this->kenaikan->pratinjau($asal, $tahunAjaran) : [],
            'kelasTujuan' => $tahunAjaran->kelas()->orderBy('jenjang')->orderBy('nama_kelas')->get()
                ->map(fn ($k) => ['id' => $k->id, 'nama' => $k->nama_lengkap, 'jenjang' => $k->jenjang]),
        ]);
    }

    public function jalankanKenaikan(Request $request, TahunAjaran $tahunAjaran): RedirectResponse
    {
        $data = $request->validate([
            'tujuan' => ['array'],
            'tujuan.*' => ['nullable', 'integer'],
            'tinggal' => ['array'],
            'tinggal.*' => ['integer'],
        ]);
        $asal = $this->kenaikan->asal($tahunAjaran) ?? abort(404);

        $hasil = $this->kenaikan->jalankan($asal, $tahunAjaran, $data['tujuan'] ?? [], $data['tinggal'] ?? []);

        return $this->kembali("Kenaikan kelas selesai: {$hasil['naik']} naik kelas, {$hasil['tinggal']} tinggal kelas, {$hasil['lulus']} lulus"
            .($hasil['dilewati'] > 0 ? ", {$hasil['dilewati']} dilewati karena sudah punya kelas" : '').'.');
    }

    private function validasi(Request $request): array
    {
        return $request->validate([
            'nama' => ['required', 'string', 'max:9'],
            'tanggal_mulai' => ['required', 'date'],
            'tanggal_selesai' => ['required', 'date'],
            'semester' => ['required', 'array', 'size:2'],
            'semester.*.tanggal_mulai' => ['required', 'date'],
            'semester.*.tanggal_selesai' => ['required', 'date'],
        ], ['nama.required' => 'Nama tahun ajaran wajib diisi, mis. 2027/2028.']);
    }

    private function kembali(string $pesan): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $pesan]);

        return back();
    }
}
