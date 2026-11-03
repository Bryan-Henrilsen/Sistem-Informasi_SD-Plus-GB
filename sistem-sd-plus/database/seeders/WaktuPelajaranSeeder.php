<?php

namespace Database\Seeders;

use App\Domains\JadwalGuru\Models\JadwalMengajar;
use App\Domains\JadwalGuru\Models\WaktuPelajaran;
use App\Models\TahunAjaran;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class WaktuPelajaranSeeder extends Seeder
{
    public function run(): void
    {
        $tahunAjaran = TahunAjaran::aktif()->firstOrFail();
        $tahunAjaranId = $tahunAjaran->id;

        $seninSampaiJumat = [1, 2, 3, 4, 5];
        $seninSampaiKamis = [1, 2, 3, 4];

        // 14 interval waktu
        // Jam 1 dimulai pukul 07:00, bukan setelah slot upacara tambahan.
        $slots = [
            ['urutan' => 1,  
            'jam_ke' => 1,  
            'nama' => 'Jam 1',         
            'jam_mulai' => '07:00:00', 
            'jam_selesai' => '07:35:00', 
            'jenis_kegiatan' => 'kbm',       
            'jenjang_min' => 1, 
            'jenjang_max' => 6, 
            'hari' => $seninSampaiJumat],

            ['urutan' => 2,
              'jam_ke' => 2,  
              'nama' => 'Jam 2',         
              'jam_mulai' => '07:35:00', 
              'jam_selesai' => '08:10:00', 
              'jenis_kegiatan' => 'kbm',       
              'jenjang_min' => 1, 
              'jenjang_max' => 6, 
              'hari' => $seninSampaiJumat],

            ['urutan' => 3,  
            'jam_ke' => 3,  
            'nama' => 'Jam 3',         
            'jam_mulai' => '08:10:00', 
            'jam_selesai' => '08:45:00', 
            'jenis_kegiatan' => 'kbm',       
            'jenjang_min' => 1, 
            'jenjang_max' => 6, 
            'hari' => $seninSampaiJumat],

            ['urutan' => 4,  
            'jam_ke' => 4,  
            'nama' => 'Jam 4',         
            'jam_mulai' => '08:45:00', 
            'jam_selesai' => '09:20:00', 
            'jenis_kegiatan' => 'kbm',       
            'jenjang_min' => 1, 
            'jenjang_max' => 6, 
            'hari' => $seninSampaiJumat],

            ['urutan' => 5,  
            'jam_ke' => null, 
            'nama' => 'Istirahat 1',  
            'jam_mulai' => '09:20:00', 
            'jam_selesai' => '09:35:00', 
            'jenis_kegiatan' => 'istirahat', 
            'jenjang_min' => 1, 
            'jenjang_max' => 6, 
            'hari' => $seninSampaiJumat],

            ['urutan' => 6,  
            'jam_ke' => 5,  
            'nama' => 'Jam 5',         
            'jam_mulai' => '09:35:00', 
            'jam_selesai' => '10:10:00', 
            'jenis_kegiatan' => 'kbm',       
            'jenjang_min' => 1, 
            'jenjang_max' => 6, 
            'hari' => $seninSampaiJumat],

            ['urutan' => 7,  
            'jam_ke' => 6,  
            'nama' => 'Jam 6',         
            'jam_mulai' => '10:10:00', 
            'jam_selesai' => '10:45:00', 
            'jenis_kegiatan' => 'kbm',       
            'jenjang_min' => 1, 
            'jenjang_max' => 6, 
            'hari' => $seninSampaiJumat],

            ['urutan' => 8,  
            'jam_ke' => 7,  
            'nama' => 'Jam 7',         
            'jam_mulai' => '10:45:00', 
            'jam_selesai' => '11:20:00', 
            'jenis_kegiatan' => 'kbm',       
            'jenjang_min' => 1, 
            'jenjang_max' => 6, 
            'hari' => $seninSampaiJumat],

            ['urutan' => 9,  
            'jam_ke' => null, 
            'nama' => 'Istirahat 2',  
            'jam_mulai' => '11:20:00', 
            'jam_selesai' => '11:45:00', 
            'jenis_kegiatan' => 'istirahat', 
            'jenjang_min' => 1, 
            'jenjang_max' => 6, 
            'hari' => $seninSampaiJumat],

            ['urutan' => 10, 
            'jam_ke' => 8,  
            'nama' => 'Jam 8',         
            'jam_mulai' => '11:45:00', 
            'jam_selesai' => '12:20:00', 
            'jenis_kegiatan' => 'kbm',       
            'jenjang_min' => 1, 
            'jenjang_max' => 6, 
            'hari' => $seninSampaiJumat],

            ['urutan' => 11, 
            'jam_ke' => 9,  
            'nama' => 'Jam 9',         
            'jam_mulai' => '12:20:00', 
            'jam_selesai' => '12:55:00', 
            'jenis_kegiatan' => 'kbm',       
            'jenjang_min' => 1, 
            'jenjang_max' => 6, 
            'hari' => $seninSampaiJumat],

            ['urutan' => 12, 
            'jam_ke' => 10, 
            'nama' => 'Jam 10',        
            'jam_mulai' => '12:55:00', 
            'jam_selesai' => '13:30:00', 
            'jenis_kegiatan' => 'kbm',       
            'jenjang_min' => 1, 
            'jenjang_max' => 6, 
            'hari' => $seninSampaiKamis],

            ['urutan' => 13, 
            'jam_ke' => null, 
            'nama' => 'Istirahat 3', 
            'jam_mulai' => '13:30:00', 
            'jam_selesai' => '13:45:00', 
            'jenis_kegiatan' => 'istirahat', 
            'jenjang_min' => 3, 
            'jenjang_max' => 6, 
            'hari' => $seninSampaiKamis],

            ['urutan' => 14, 
            'jam_ke' => 11, 
            'nama' => 'Jam 11',        
            'jam_mulai' => '13:45:00', 
            'jam_selesai' => '14:30:00', 
            'jenis_kegiatan' => 'kbm',       
            'jenjang_min' => 3, 
            'jenjang_max' => 6, 
            'hari' => $seninSampaiKamis],
        ];

        DB::transaction(function () use ($tahunAjaranId, $slots): void {
            $existingIds = WaktuPelajaran::where('tahun_ajaran_id', $tahunAjaranId)
                ->pluck('id');

            if ($existingIds->isNotEmpty() && JadwalMengajar::whereIn('waktu_pelajaran_id', $existingIds)->exists()) {
                throw new RuntimeException(
                    'Seeder dibatalkan: sudah ada jadwal_mengajar yang memakai slot tahun ajaran aktif. '
                    . 'Jangan hapus slot tersebut; lakukan penyesuaian melalui migration/data migration khusus.'
                );
            }

            // Hapus slot draft untuk tahun ajaran aktif. Relasi hari ikut terhapus melalui ON DELETE CASCADE pada foreign key waktu_pelajaran_hari.
            WaktuPelajaran::where('tahun_ajaran_id', $tahunAjaranId)->delete();

            foreach ($slots as $slot) {
                $waktuPelajaran = WaktuPelajaran::create([
                    'tahun_ajaran_id' => $tahunAjaranId,
                    'urutan' => $slot['urutan'],
                    'jam_ke' => $slot['jam_ke'],
                    'nama' => $slot['nama'],
                    'jam_mulai' => $slot['jam_mulai'],
                    'jam_selesai' => $slot['jam_selesai'],
                    'jenis_kegiatan' => $slot['jenis_kegiatan'],
                    'jenjang_min' => $slot['jenjang_min'],
                    'jenjang_max' => $slot['jenjang_max'],
                ]);

                $hariRows = [];
                foreach ($slot['hari'] as $hari) {
                    $hariRows[] = [
                        'waktu_pelajaran_id' => $waktuPelajaran->id,
                        'hari' => $hari,
                    ];
                }

                DB::table('waktu_pelajaran_hari')->insert($hariRows);
            }
        });
    }
}
