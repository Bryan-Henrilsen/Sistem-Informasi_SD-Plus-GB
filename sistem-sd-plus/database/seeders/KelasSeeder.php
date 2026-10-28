<?php

namespace Database\Seeders;

use App\Models\TahunAjaran;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class KelasSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $ta = TahunAjaran::aktif()->firstOrFail();

        $rombel = [
            '1A Gregory', '1B Ignatius', '2A Fran Xavier', '2B Monica', '2C Gemma',
            '3A Alphonsus', '3B John', '3C Margareth', '4A Elizabeth', '4B John',
            '5A Peter', '5B Dominica', '5C Padre Pio', '6A Joseph', '6B Mary',
        ];

        foreach ($rombel as $nama) {
            [$namaKelas, $pelindung] = explode(' ', $nama, 2);

            $ta->kelas()->create([
                'jenjang' => (int) $namaKelas[0],
                'nama_kelas' => $namaKelas,
                'nama_pelindung' => $pelindung,
            ]);
        }
    }
}
