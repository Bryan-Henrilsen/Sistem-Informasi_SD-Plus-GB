<?php

namespace App\Domains\LesEkskul\Http\Controllers;

use App\Domains\LesEkskul\Services\ImporSiswaService;
use App\Domains\LesEkskul\Services\PembacaExcelSiswa;
use App\Http\Controllers\Controller;
use App\Models\TahunAjaran;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

// Halaman Impor Siswa (FR-28, Ringkasan v1.11 #53), khusus TU. Unggah → pratinjau → simpan.
// File tidak disimpan: dibaca sekali saat diunggah, hanya baris hasil bacaannya yang disimpan sementara di sesi
class ImporSiswaController extends Controller
{
    private const SESI = 'impor_siswa';

    public function __construct(private ImporSiswaService $service, private PembacaExcelSiswa $pembaca) {}

    public function index(Request $request): Response
    {
        $sesi = $request->session()->get(self::SESI);
        $tahunAjaran = $sesi ? TahunAjaran::find($sesi['tahun_ajaran_id']) : null;

        return Inertia::render('les-ekskul/siswa/impor', [
            'daftarTahun' => TahunAjaran::orderByDesc('tanggal_mulai')->get()
                ->map(fn (TahunAjaran $t) => ['id' => $t->id, 'nama' => $t->nama, 'aktif' => $t->status_aktif]),
            // Pratinjau dihitung ulang dari data terbaru setiap halaman dibuka
            'pratinjau' => $sesi && $tahunAjaran ? [
                'tahap' => $sesi['tahap'],
                'nama_file' => $sesi['nama_file'],
                'tahun_ajaran' => ['id' => $tahunAjaran->id, 'nama' => $tahunAjaran->nama],
                ...$this->service->pratinjau($sesi['tahap'], $tahunAjaran, $sesi['baris']),
            ] : null,
        ]);
    }

    // Template Juli untuk tahun ajaran yang dipilih (diunduh, tidak disimpan di server)
    public function template(Request $request): StreamedResponse
    {
        $tahunAjaran = TahunAjaran::findOrFail($request->integer('tahun') ?: TahunAjaran::orderByDesc('tanggal_mulai')->value('id'));
        $buku = $this->pembaca->template($tahunAjaran);

        return response()->streamDownload(
            fn () => (new Xlsx($buku))->save('php://output'),
            'Template_Impor_Siswa_'.str_replace(['/', ' '], ['-', '_'], $tahunAjaran->nama).'.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    public function unggah(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'tahap' => ['required', Rule::in([ImporSiswaService::JULI, ImporSiswaService::DAPODIK])],
            'tahun_ajaran_id' => ['required_if:tahap,'.ImporSiswaService::JULI, 'nullable', 'integer', 'exists:tahun_ajaran,id'],
            'file' => ['required', 'file', 'mimes:xls,xlsx', 'max:5120'],
        ], [
            'file.required' => 'Pilih file Excel.',
            'file.mimes' => 'File harus berformat Excel (.xls atau .xlsx).',
            'file.max' => 'Ukuran file paling besar 5 MB.',
            'tahun_ajaran_id.required_if' => 'Pilih tahun ajaran tujuan.',
        ]);

        // Dapodik selalu untuk tahun ajaran aktif
        $tahunAjaran = $data['tahap'] === ImporSiswaService::DAPODIK
            ? TahunAjaran::aktif()->firstOrFail()
            : TahunAjaran::findOrFail($data['tahun_ajaran_id']);
        $path = $request->file('file')->getRealPath();
        $baris = $data['tahap'] === ImporSiswaService::JULI ? $this->pembaca->bacaJuli($path) : $this->pembaca->bacaDapodik($path);

        $request->session()->put(self::SESI, [
            'tahap' => $data['tahap'],
            'tahun_ajaran_id' => $tahunAjaran->id,
            'nama_file' => $request->file('file')->getClientOriginalName(),
            'baris' => $baris,
        ]);

        return redirect()->route('les-ekskul.impor-siswa.index');
    }

    public function simpan(Request $request): RedirectResponse
    {
        $sesi = $request->session()->get(self::SESI) ?? abort(409, 'Tidak ada impor yang sedang ditinjau. Unggah file lagi.');
        $data = $request->validate([
            'keputusan' => ['array'],
            'keputusan.*.aksi' => ['nullable', Rule::in(['cocok', 'baru', 'lewati'])],
            'keputusan.*.siswa_id' => ['nullable', 'integer'],
            'keputusan.*.nama' => ['nullable', Rule::in(['aplikasi', 'file'])],
        ]);

        $tahunAjaran = TahunAjaran::findOrFail($sesi['tahun_ajaran_id']);
        $jumlah = $this->service->simpan($sesi['tahap'], $tahunAjaran, $sesi['baris'], $data['keputusan'] ?? []);
        $request->session()->forget(self::SESI);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Impor selesai: {$jumlah['baru']} siswa baru, {$jumlah['dicocokkan']} dicocokkan"
            .($jumlah['dipindah'] ? " ({$jumlah['dipindah']} pindah kelas)" : '').", {$jumlah['dilewati']} dilewati."]);

        return redirect()->route('les-ekskul.data-siswa.index', ['tahun' => $tahunAjaran->id]);
    }

    public function batal(Request $request): RedirectResponse
    {
        $request->session()->forget(self::SESI);

        return redirect()->route('les-ekskul.impor-siswa.index');
    }
}
