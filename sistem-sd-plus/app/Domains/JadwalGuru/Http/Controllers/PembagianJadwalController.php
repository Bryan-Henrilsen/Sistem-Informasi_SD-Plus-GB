<?php

namespace App\Domains\JadwalGuru\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Domains\JadwalGuru\Services\JadwalService;
use App\Domains\JadwalGuru\Models\JadwalMengajar;
use App\Domains\JadwalGuru\Models\WaktuPelajaran;
use App\Domains\JadwalGuru\Models\KisiKisiTugas;
use App\Models\Kelas;
use App\Models\TahunAjaran;
use Inertia\Inertia;
use Exception;

class PembagianJadwalController extends Controller
{
    protected $jadwalService;

    public function __construct(JadwalService $jadwalService)
    {
        $this->jadwalService = $jadwalService;
    }

    /**
     * Menampilkan halaman UI Pembagian Jadwal
     */
    public function index()
    {
        $tahunAjaran = TahunAjaran::aktif()->firstOrFail();

        $tahunAjaranId = $tahunAjaran->id;
        // 1. Waktu pelajaran
        $waktuPelajaran = WaktuPelajaran::where(
            'tahun_ajaran_id',
            $tahunAjaran->id
        )
            ->with('hariBerlaku')
            ->orderBy('urutan', 'asc')
            ->get();

        // 2. Daftar kelas
        $daftarKelas = Kelas::where(
            'tahun_ajaran_id',
            $tahunAjaran->id
        )
            ->orderBy('jenjang')
            ->orderBy('nama_kelas')
            ->get();

        // 3. Kisi-kisi tugas
        $kisiKisiTugas = KisiKisiTugas::where(
            'tahun_ajaran_id',
            $tahunAjaran->id
        )
            ->with([
                'guru.user',
                'kelas',
                'mataPelajaran',
                'jenisTugas',
                'jadwalMengajar',
            ])
            ->get()
            ->map(function ($kisi) {
                $kisi->sisa_jam_tersedia = max(
                    0,
                    $kisi->jumlah_jam - $kisi->jadwalMengajar->count()
                );

                return $kisi;
            })
            ->filter(fn ($kisi) => $kisi->sisa_jam_tersedia > 0)
            ->values();

        // 4. Jadwal aktual
        $jadwalTerplot = JadwalMengajar::whereHas(
            'kisiKisiTugas',
            function ($query) use ($tahunAjaran) {
                $query->where(
                    'tahun_ajaran_id',
                    $tahunAjaran->id
                );
            }
        )
            ->with([
                'guru.user',
                'kisiKisiTugas.mataPelajaran',
                'jenisTugas',
            ])
            ->get();

        return Inertia::render('jadwal-guru/PembagianJadwal', [
            'waktuPelajaran' => $waktuPelajaran,
            'daftarKelas' => $daftarKelas,
            'kisiKisiTugas' => $kisiKisiTugas,
            'jadwalTerplot' => $jadwalTerplot,
        ]);
    }

    /**
     * Menyimpan jadwal baru dari aksi Drag & Drop
     */
    public function store(Request $request)
    {
        $request->validate([
            'kisi_kisi_tugas_id' => 'required|exists:kisi_kisi_tugas,id',
            'waktu_pelajaran_id' => 'required|exists:waktu_pelajaran,id',
            'hari'               => 'required|integer|between:1,5', // 1=Senin .. 5=Jumat
        ]);

        try {
            $this->jadwalService->assignJadwal(
                $request->kisi_kisi_tugas_id,
                $request->waktu_pelajaran_id,
                $request->hari
            );
            
            return redirect()->back()->with('success', 'Jadwal berhasil ditambahkan.');
        } catch (Exception $e) {
            return redirect()->back()->withErrors(['message' => $e->getMessage()]);
        }
    }

    /**
     * Menghapus jadwal (Menarik kartu dari grid kembali ke sidebar)
     */
    public function destroy($id)
    {
        try {
            $this->jadwalService->removeJadwal($id);
            return redirect()->back()->with('success', 'Jadwal berhasil ditarik.');
        } catch (Exception $e) {
            return redirect()->back()->withErrors(['message' => 'Gagal menarik jadwal: ' . $e->getMessage()]);
        }
    }
}