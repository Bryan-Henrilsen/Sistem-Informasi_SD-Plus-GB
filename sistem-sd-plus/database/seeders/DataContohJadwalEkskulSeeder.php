<?php

namespace Database\Seeders;

use App\Domains\LesEkskul\Models\Ekstrakurikuler;
use App\Domains\LesEkskul\Models\JadwalEkskul;
use App\Models\TahunAjaran;
use Illuminate\Database\Seeder;

// DATA CONTOH jadwal ekskul + kelas paralel English Club untuk demo deteksi bentrok (FR-5) dan assign kelas paralel (FR-27).
// Jam dan lokasi perkiraan, bukan jadwal sekolah. Aman dijalankan berulang (firstOrCreate), tidak membuat data dobel.
// Jalankan setelah DataContohLesEkskulSeeder: php artisan db:seed --class=DataContohJadwalEkskulSeeder
class DataContohJadwalEkskulSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command->warn('Data contoh tidak boleh dijalankan di produksi.');

            return;
        }

        $tahunAjaran = TahunAjaran::aktif()->firstOrFail();

        // hari: 5 = Jumat, 6 = Sabtu; jenjang diisi bila jamnya beda per jenjang
        $jadwal = [
            'Basket' => [
                ['hari' => 6, 'jam_mulai' => '07:00', 'jam_selesai' => '08:00', 'jenjang_min' => 1, 'jenjang_max' => 3, 'lokasi' => 'Lapangan'],
                ['hari' => 6, 'jam_mulai' => '08:00', 'jam_selesai' => '09:00', 'jenjang_min' => 4, 'jenjang_max' => 6, 'lokasi' => 'Lapangan'],
            ],
            'English Club' => [['hari' => 5, 'jam_mulai' => '14:00', 'jam_selesai' => '15:00', 'lokasi' => 'Ruang kelas']],
            'Swimming' => [['hari' => 6, 'jam_mulai' => '15:30', 'jam_selesai' => '17:00', 'lokasi' => 'Kolam renang']],
            'Coding' => [['hari' => 5, 'jam_mulai' => '15:00', 'jam_selesai' => '16:00', 'lokasi' => 'Lab komputer']],
            // Sengaja sama jamnya dengan English Club, untuk mencoba penolakan bentrok jadwal
            'Olimpiade MTK' => [['hari' => 5, 'jam_mulai' => '14:00', 'jam_selesai' => '15:00', 'lokasi' => 'Perpustakaan']],
        ];

        foreach ($jadwal as $nama => $daftar) {
            $ekskul = Ekstrakurikuler::where('tahun_ajaran_id', $tahunAjaran->id)->kegiatanInduk()->where('nama', $nama)->first();

            foreach ($ekskul ? $daftar : [] as $baris) {
                JadwalEkskul::firstOrCreate(['ekstrakurikuler_id' => $ekskul->id, 'hari' => $baris['hari'], 'jam_mulai' => $baris['jam_mulai']], $baris);
            }
        }

        // Kelas paralel English Club (biaya sama dengan induk, A6 #8); jadwalnya ikut induk
        $english = Ekstrakurikuler::where('tahun_ajaran_id', $tahunAjaran->id)->kegiatanInduk()->where('nama', 'English Club')->first();

        foreach ($english ? ['Starter', 'Mover', 'Flyer'] : [] as $tingkat) {
            Ekstrakurikuler::firstOrCreate(
                ['tahun_ajaran_id' => $tahunAjaran->id, 'parent_ekstrakurikuler_id' => $english->id, 'nama' => "English Club {$tingkat}"],
                ['biaya' => $english->biaya, 'kuota' => 15],
            );
        }

        $this->command->info('Jadwal ekskul dan kelas paralel English Club contoh selesai dibuat.');
    }
}
