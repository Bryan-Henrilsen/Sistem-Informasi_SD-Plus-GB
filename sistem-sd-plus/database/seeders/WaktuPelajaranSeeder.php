<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Domains\JadwalGuru\Models\WaktuPelajaran;
use App\Domains\JadwalGuru\Models\WaktuPelajaranHari;

class WaktuPelajaranSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Pastikan kita mengambil ID Tahun Ajaran yang aktif (Asumsi ID 1 adalah 2026/2027)
        // Jika belum ada, seeder ini akan meminjam/membuat ID 1
        $tahunAjaranId = 1; 

        // Aturan Hari: 1=Senin, 2=Selasa, 3=Rabu, 4=Kamis, 5=Jumat
        $seninSampaiJumat = [1, 2, 3, 4, 5];
        $seninSampaiKamis = [1, 2, 3, 4];

        // Draf Data Waktu Pelajaran berdasarkan PRD B.3 (Jam_Belajar_SD_Plus.xlsx)
        $slots = [
            // Jam 1 - 9 & Istirahat 1 - 2 berlaku untuk SEMUA jenjang (Kelas 1 - 6) di hari Senin - Jumat
            ['urutan' => 1, 'jam_ke' => null, 'nama' => 'Upacara / Pembiasaan', 'jam_mulai' => '07:00:00', 'jam_selesai' => '07:35:00', 'jenis_kegiatan' => 'upacara', 'jenjang_min' => 1, 'jenjang_max' => 6, 'hari' => [1]], // Hanya Senin
            ['urutan' => 2, 'jam_ke' => 1, 'nama' => 'Jam 1', 'jam_mulai' => '07:35:00', 'jam_selesai' => '08:10:00', 'jenis_kegiatan' => 'kbm', 'jenjang_min' => 1, 'jenjang_max' => 6, 'hari' => $seninSampaiJumat],
            ['urutan' => 3, 'jam_ke' => 2, 'nama' => 'Jam 2', 'jam_mulai' => '08:10:00', 'jam_selesai' => '08:45:00', 'jenis_kegiatan' => 'kbm', 'jenjang_min' => 1, 'jenjang_max' => 6, 'hari' => $seninSampaiJumat],
            ['urutan' => 4, 'jam_ke' => null, 'nama' => 'Istirahat 1', 'jam_mulai' => '08:45:00', 'jam_selesai' => '09:15:00', 'jenis_kegiatan' => 'istirahat', 'jenjang_min' => 1, 'jenjang_max' => 6, 'hari' => $seninSampaiJumat],
            ['urutan' => 5, 'jam_ke' => 3, 'nama' => 'Jam 3', 'jam_mulai' => '09:15:00', 'jam_selesai' => '09:50:00', 'jenis_kegiatan' => 'kbm', 'jenjang_min' => 1, 'jenjang_max' => 6, 'hari' => $seninSampaiJumat],
            ['urutan' => 6, 'jam_ke' => 4, 'nama' => 'Jam 4', 'jam_mulai' => '09:50:00', 'jam_selesai' => '10:25:00', 'jenis_kegiatan' => 'kbm', 'jenjang_min' => 1, 'jenjang_max' => 6, 'hari' => $seninSampaiJumat],
            ['urutan' => 7, 'jam_ke' => null, 'nama' => 'Istirahat 2', 'jam_mulai' => '10:25:00', 'jam_selesai' => '10:40:00', 'jenis_kegiatan' => 'istirahat', 'jenjang_min' => 1, 'jenjang_max' => 6, 'hari' => $seninSampaiJumat],
            ['urutan' => 8, 'jam_ke' => 5, 'nama' => 'Jam 5', 'jam_mulai' => '10:40:00', 'jam_selesai' => '11:15:00', 'jenis_kegiatan' => 'kbm', 'jenjang_min' => 1, 'jenjang_max' => 6, 'hari' => $seninSampaiJumat],
            ['urutan' => 9, 'jam_ke' => 6, 'nama' => 'Jam 6', 'jam_mulai' => '11:15:00', 'jam_selesai' => '11:50:00', 'jenis_kegiatan' => 'kbm', 'jenjang_min' => 1, 'jenjang_max' => 6, 'hari' => $seninSampaiJumat],
            
            // Jam 7-9 (Anggap KBM Lanjutan siang hari)
            ['urutan' => 10, 'jam_ke' => 7, 'nama' => 'Jam 7', 'jam_mulai' => '11:50:00', 'jam_selesai' => '12:25:00', 'jenis_kegiatan' => 'kbm', 'jenjang_min' => 1, 'jenjang_max' => 6, 'hari' => $seninSampaiKamis],
            ['urutan' => 11, 'jam_ke' => 8, 'nama' => 'Jam 8', 'jam_mulai' => '12:25:00', 'jam_selesai' => '13:00:00', 'jenis_kegiatan' => 'kbm', 'jenjang_min' => 1, 'jenjang_max' => 6, 'hari' => $seninSampaiKamis],
            ['urutan' => 12, 'jam_ke' => 9, 'nama' => 'Jam 9', 'jam_mulai' => '13:00:00', 'jam_selesai' => '13:35:00', 'jenis_kegiatan' => 'kbm', 'jenjang_min' => 1, 'jenjang_max' => 6, 'hari' => $seninSampaiKamis],
            
            // Perbedaan Ekstrem PRD B.3:
            // Jam 10 berlaku untuk Jenjang 1-6 (Senin-Kamis)
            ['urutan' => 13, 'jam_ke' => 10, 'nama' => 'Jam 10', 'jam_mulai' => '13:35:00', 'jam_selesai' => '14:10:00', 'jenis_kegiatan' => 'kbm', 'jenjang_min' => 1, 'jenjang_max' => 6, 'hari' => $seninSampaiKamis],
            
            // Istirahat 3 dan Jam 11 (Durasi 45 menit) HANYA berlaku untuk Jenjang 3-6 (Senin-Kamis)
            ['urutan' => 14, 'jam_ke' => null, 'nama' => 'Istirahat 3', 'jam_mulai' => '14:10:00', 'jam_selesai' => '14:25:00', 'jenis_kegiatan' => 'istirahat', 'jenjang_min' => 3, 'jenjang_max' => 6, 'hari' => $seninSampaiKamis],
            ['urutan' => 15, 'jam_ke' => 11, 'nama' => 'Jam 11', 'jam_mulai' => '14:25:00', 'jam_selesai' => '15:10:00', 'jenis_kegiatan' => 'kbm', 'jenjang_min' => 3, 'jenjang_max' => 6, 'hari' => $seninSampaiKamis],
        ];

        DB::beginTransaction();
        try {
            foreach ($slots as $slot) {
                // 1. Insert ke tabel waktu_pelajaran
                $waktuPelajaran = WaktuPelajaran::create([
                    'tahun_ajaran_id' => $tahunAjaranId,
                    'urutan'          => $slot['urutan'],
                    'jam_ke'          => $slot['jam_ke'],
                    'nama'            => $slot['nama'],
                    'jam_mulai'       => $slot['jam_mulai'],
                    'jam_selesai'     => $slot['jam_selesai'],
                    'jenis_kegiatan'  => $slot['jenis_kegiatan'],
                    'jenjang_min'     => $slot['jenjang_min'],
                    'jenjang_max'     => $slot['jenjang_max'],
                ]);

                // 2. Insert ke tabel waktu_pelajaran_hari (Mapping)
                foreach ($slot['hari'] as $hariInt) {
                    WaktuPelajaranHari::create([
                        'waktu_pelajaran_id' => $waktuPelajaran->id,
                        'hari'               => $hariInt,
                    ]);
                }
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }
}