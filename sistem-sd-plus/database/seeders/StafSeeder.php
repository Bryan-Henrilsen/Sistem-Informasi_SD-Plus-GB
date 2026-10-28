<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class StafSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $staf = [
            ['Antonius Yayan, S.Pd.', 'kepsek@sdplus.test', 'kepala_sekolah'],
            ['Serafina Fuijun, A.Md.', 'serafina@sdplus.test', 'tu'],
            ['Bryan Henrilsen', 'bryan@sdplus.test', 'tu'],
        ];

        foreach ($staf as [$nama, $email, $role]) {
            User::create(['name' => $nama, 'email' => $email, 'password' => 'password'])
                ->assignRole($role);
        }
    }
}
