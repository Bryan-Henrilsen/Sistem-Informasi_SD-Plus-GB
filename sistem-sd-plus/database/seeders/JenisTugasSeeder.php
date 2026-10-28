<?php

namespace Database\Seeders;

use App\Models\JenisTugas;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class JenisTugasSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (['utama' => 'Mengajar Utama', 'damping' => 'Damping', 'piket' => 'Piket'] as $kode => $nama) {
            JenisTugas::create(['kode' => $kode, 'nama' => $nama]);
        }
    }
}
