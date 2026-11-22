<?php

namespace App\Domains\LesEkskul\Http\Controllers;

use App\Domains\LesEkskul\Services\MataPelajaranService;
use App\Http\Controllers\Controller;
use App\Models\JenisTugas;
use App\Models\MataPelajaran;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

// Mata Pelajaran & Jenis Tugas — Waka Kurikulum & Kepala Sekolah (Ringkasan v1.11 #47)
class MataPelajaranController extends Controller
{
    public function __construct(private MataPelajaranService $service) {}

    public function index(): Response
    {
        return Inertia::render('les-ekskul/mata-pelajaran', [
            'mapel' => $this->service->daftar(),
            'jenisTugas' => $this->service->jenisTugas(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $mapel = $this->service->simpan($this->validasi($request));

        return $this->kembali("{$mapel->nama} ditambahkan.");
    }

    public function update(Request $request, MataPelajaran $mataPelajaran): RedirectResponse
    {
        $this->service->simpan($this->validasi($request), $mataPelajaran);

        return $this->kembali("{$mataPelajaran->nama} disimpan.");
    }

    public function aktif(Request $request, MataPelajaran $mataPelajaran): RedirectResponse
    {
        $aktif = $request->boolean('aktif');
        $this->service->aturAktif($mataPelajaran, $aktif);

        return $this->kembali($aktif
            ? "{$mataPelajaran->nama} aktif kembali."
            : "{$mataPelajaran->nama} dinonaktifkan; tidak ditawarkan lagi di pilihan baru, data lama tetap.");
    }

    public function destroy(MataPelajaran $mataPelajaran): RedirectResponse
    {
        $nama = $mataPelajaran->nama;
        $this->service->hapus($mataPelajaran);

        return $this->kembali("{$nama} dihapus.");
    }

    public function ubahJenisTugas(Request $request, JenisTugas $jenisTugas): RedirectResponse
    {
        $data = $request->validate(['nama' => ['required', 'string', 'max:100']], ['nama.required' => 'Nama wajib diisi.']);
        $this->service->ubahNamaJenisTugas($jenisTugas, $data['nama']);

        return $this->kembali('Nama jenis tugas disimpan.');
    }

    private function validasi(Request $request): array
    {
        return $request->validate([
            'kode' => ['required', 'string', 'max:10'],
            'nama' => ['required', 'string', 'max:100'],
        ], ['kode.required' => 'Kode wajib diisi, mis. MTK.', 'nama.required' => 'Nama wajib diisi.']);
    }

    private function kembali(string $pesan): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $pesan]);

        return back();
    }
}
