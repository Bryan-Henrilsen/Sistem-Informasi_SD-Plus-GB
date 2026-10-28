<?php

namespace Database\Seeders;

use App\Models\MataPelajaran;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class MataPelajaranSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $mapel = [
            'AGAMA' => 'Pendidikan Agama',   'PC'    => 'Pendidikan Pancasila',
            'BI'    => 'Bahasa Indonesia',   'MTK'   => 'Matematika',
            'IPAS'  => 'IPAS',               'SBdP'  => 'Seni Budaya',
            'MUSIK' => 'Seni Musik',         'PJOK'  => 'PJOK',
            'TIK'   => 'TIK',                'MAND'  => 'Bahasa Mandarin',
            'ENG'   => 'English',            'SCI'   => 'Science',
            'MATH'  => 'Maths',              'KT'    => 'Kerja Tugas',
        ];

        foreach ($mapel as $kode => $nama) {
            MataPelajaran::create(['kode' => $kode, 'nama' => $nama]);
        }
    }
}
