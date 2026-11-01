<?php

namespace App\Domains\JadwalGuru\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Domains\JadwalGuru\Services\JadwalService;
use App\Domains\JadwalGuru\Models\JadwalMengajar;
use App\Domains\JadwalGuru\Models\WaktuPelajaran;
use App\Domains\JadwalGuru\Models\KisiKisiTugas;
use App\Models\Kelas;
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
        // 1. Ambil Waktu Pelajaran lengkap dengan relasi harinya
        $waktuPelajaran = WaktuPelajaran::with('hari')->orderBy('urutan', 'asc')->get();

        // 2. Ambil Daftar Kelas (Diurutkan berdasarkan jenjang lalu nama)
        $daftarKelas = Kelas::orderBy('jenjang')->orderBy('nama_kelas')->get();

        // 3. Ambil Kisi-Kisi Tugas yang sisa jamnya masih > 0
        $kisiKisiTugas = KisiKisiTugas::with(['guru.user', 'kelas', 'mataPelajaran', 'jenisTugas'])
            ->get()
            // Map digunakan agar property sisa_jam dari accessor masuk ke array JSON
            ->map(function ($kisi) {
                $kisi->sisa_jam_tersedia = $kisi->sisa_jam; // Diselaraskan dengan nama variabel React
                return $kisi;
            })
            ->filter(function ($kisi) {
                return $kisi->sisa_jam_tersedia > 0;
            })->values(); // Reset array index agar rapi di React

        // 4. Ambil jadwal yang sudah terplot di kalender
        $jadwalTerplot = JadwalMengajar::with(['guru.user', 'kisiKisiTugas.mataPelajaran', 'jenisTugas'])->get();

        return Inertia::render('jadwal-guru/PembagianJadwal', [
            'waktuPelajaran' => $waktuPelajaran,
            'daftarKelas'    => $daftarKelas,
            'kisiKisiTugas'  => $kisiKisiTugas,
            'jadwalTerplot'  => $jadwalTerplot // Variabel penting yang terlupakan!
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