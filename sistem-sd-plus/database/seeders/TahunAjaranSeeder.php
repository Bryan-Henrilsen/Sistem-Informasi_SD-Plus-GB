<?php

namespace Database\Seeders;

use App\Models\TahunAjaran;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class TahunAjaranSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $ta = TahunAjaran::create([
            'nama' => '2026/2027', 'tanggal_mulai' => '2026-07-13', 'tanggal_selesai' => '2027-06-30', 'status_aktif' => true,
        ]);

        $ta->semester()->createMany([
            ['urutan' => 1, 'nama' => 'Semester 1', 'tanggal_mulai' => '2026-07-13', 'tanggal_selesai' => '2026-12-31'],
            ['urutan' => 2, 'nama' => 'Semester 2', 'tanggal_mulai' => '2027-01-01', 'tanggal_selesai' => '2027-06-30'],
        ]);
    }
}
