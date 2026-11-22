<?php

namespace App\Domains\LesEkskul\Http\Controllers;

use App\Domains\LesEkskul\Services\DataSiswaService;
use App\Http\Controllers\Controller;
use App\Models\Guru;
use App\Models\Kelas;
use App\Models\Siswa;
use App\Models\TahunAjaran;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

// Data master Siswa: TU mengubah, Kepala Sekolah & Bendahara melihat (Ringkasan v1.11 #44)
class DataSiswaController extends Controller
{
    public function __construct(private DataSiswaService $service) {}

    public function index(Request $request): Response
    {
        $tahunAjaran = $request->integer('tahun')
            ? TahunAjaran::findOrFail($request->integer('tahun'))
            : TahunAjaran::aktif()->firstOrFail();
        $filter = $request->only('kelas', 'nama', 'status');
        $bisaUbah = $request->user()->hasRole('tu');

        return Inertia::render('les-ekskul/siswa/index', [
            'tahunAjaran' => ['id' => $tahunAjaran->id, 'nama' => $tahunAjaran->nama],
            'daftarTahun' => TahunAjaran::orderByDesc('tanggal_mulai')->get(['id', 'nama'])->map(fn (TahunAjaran $t) => ['id' => $t->id, 'nama' => $t->nama]),
            'siswa' => $this->service->daftar($tahunAjaran, $filter),
            'kelas' => Kelas::where('tahun_ajaran_id', $tahunAjaran->id)->orderBy('jenjang')->orderBy('nama_kelas')->get()
                ->map(fn (Kelas $k) => ['id' => $k->id, 'nama' => $k->nama_lengkap, 'jenjang' => $k->jenjang]),
            'guru' => $bisaUbah
                ? Guru::where('status_aktif', true)->with('user:id,name')->get()->sortBy('user.name')->values()
                    ->map(fn (Guru $g) => ['id' => $g->id, 'nama' => $g->user->name])
                : [],
            'filter' => $filter,
            'hariIni' => today()->toDateString(),
            'bisaUbah' => $bisaUbah,
        ]);
    }

    public function store(Request $request, TahunAjaran $tahunAjaran): RedirectResponse
    {
        [$siswa] = $this->service->simpan($tahunAjaran, $this->validasi($request));

        return $this->kembali("{$siswa->nama} ditambahkan; orang tua sudah bisa mengklaimnya.");
    }

    public function update(Request $request, TahunAjaran $tahunAjaran, Siswa $siswa): RedirectResponse
    {
        [$siswa, $catatan] = $this->service->simpan($tahunAjaran, $this->validasi($request, $siswa), $siswa);

        return $this->kembali(trim("Data {$siswa->nama} disimpan. {$catatan}"));
    }

    public function keluar(Request $request, Siswa $siswa): RedirectResponse
    {
        $data = $request->validate([
            'tanggal' => ['required', 'date'],
            'alasan' => ['required', 'string', 'max:255'],
        ], ['tanggal.required' => 'Isi tanggal efektif keluar.', 'alasan.required' => 'Isi alasan keluar, mis. pindah ke sekolah lain.']);

        $hasil = $this->service->keluar($siswa, $data['tanggal'], $data['alasan'], $request->user());
        $refund = $hasil['refund'] > 0 ? ' Refund Rp'.number_format($hasil['refund'], 0, ',', '.').' dicatat di halaman Refund.' : '';

        return $this->kembali("{$siswa->nama} keluar sekolah; {$hasil['kegiatan']} kegiatan diberhentikan.{$refund}");
    }

    public function aktifkan(Siswa $siswa): RedirectResponse
    {
        $this->service->aktifkanKembali($siswa);

        return $this->kembali("{$siswa->nama} aktif kembali. Daftarkan ulang kegiatannya bila perlu.");
    }

    public function destroy(Siswa $siswa): RedirectResponse
    {
        $nama = $siswa->nama;
        $this->service->hapus($siswa);

        return $this->kembali("{$nama} dihapus.");
    }

    private function validasi(Request $request, ?Siswa $siswa = null): array
    {
        return $request->validate([
            'nama' => ['required', 'string', 'max:255'],
            'jenis_kelamin' => ['nullable', Rule::in(['L', 'P'])],
            'nis' => ['nullable', 'string', 'max:20', Rule::unique('siswa', 'nis')->ignore($siswa?->id)],
            'nisn' => ['nullable', 'string', 'max:20', Rule::unique('siswa', 'nisn')->ignore($siswa?->id)],
            'tanggal_lahir' => ['nullable', 'date', 'before:today'],
            'anak_guru_id' => ['nullable', 'integer', 'exists:guru,id'],
            'kelas_id' => ['required', 'integer', 'exists:kelas,id'],
        ], [
            'nama.required' => 'Nama wajib diisi.',
            'kelas_id.required' => 'Pilih kelas.',
            'nis.unique' => 'NIS ini sudah dipakai siswa lain.',
            'nisn.unique' => 'NISN ini sudah dipakai siswa lain.',
        ]);
    }

    private function kembali(string $pesan): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $pesan]);

        return back();
    }
}
