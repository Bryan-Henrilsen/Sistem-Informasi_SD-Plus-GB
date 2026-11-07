<?php

namespace Database\Seeders;

use App\Domains\LesEkskul\Models\HonorFlatJenis;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class HonorFlatJenisSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $daftar = [
            'olimpiade' => 'Olimpiade',
            'bina_karakter' => 'Bina Karakter',
            'pramuka' => 'Pramuka',
            'pembuat_soal_les' => 'Pembuat Soal Les',
        ];

        foreach ($daftar as $kode => $nama) {
            HonorFlatJenis::updateOrCreate(['kode' => $kode], ['nama' => $nama]);
        }
    }
}
