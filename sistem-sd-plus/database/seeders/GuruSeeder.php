<?php

namespace Database\Seeders;

use App\Models\Guru;
use App\Models\MataPelajaran;
use App\Models\TahunAjaran;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class GuruSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $ta = TahunAjaran::aktif()->firstOrFail();
        $mapelIds = MataPelajaran::pluck('id', 'kode');

        // [nama, kode_guru, wali_kelas, kode_mapel]
        $data = [
            ['Lydia Angela, S.Kom.', 'LYD', '1A', ['TIK', 'KT']],
            ['Maria Devi Arseno, S.Pd.Gr., M.Si.', 'DV', '1B', ['MTK', 'PC', 'KT']],
            ['Angela Genoveva J. G., S.Pd.', 'A', '2A', ['ENG', 'KT']],
            ['Fransiska Ayu Lestari, S.Pd.', 'AY', '2B', ['BI', 'KT']],
            ['Paulus Aan, S.Pd.', 'PAU', '2C', ['MTK', 'PC', 'KT']],
            ['Yuvensius Firdaus Gunardi, S.Pd.', 'YV', '3A', ['ENG', 'MATH', 'KT']],
            ['Amandus Yonatan, M.Pd.', 'AM', '3B', ['IPAS', 'PC', 'KT']],
            ['Mega Sulastri, S.Si., S.Pd., M.T.', 'MG', '3C', ['MTK', 'KT']],
            ['Maria Clara Cinthya, S.Pd.', 'CIN', '4A', ['ENG', 'SCI', 'KT']],
            ['Ruben Andre, S.Pd.', 'RB', '4B', ['MATH', 'MUSIK', 'KT']],
            ['Tjin Yanes, S.Pd.', 'YN', '5A', ['ENG', 'MATH', 'KT']],
            ['Imelda Hela Sidinantega, S.Pd.', 'IM', '5B', ['MTK', 'PC', 'KT']],
            ['Raymundus Wendi, S.Pd.', 'RY', '5C', ['BI', 'PC', 'KT']],
            ['Marius Riki Olasesa, S.Pd.', 'RK', '6A', ['ENG', 'SCI', 'MUSIK', 'KT']],
            ['Kartika Citra, S.Pd.', 'TK', '6B', ['IPAS', 'PC', 'KT']],
            ['Farica Agustin, S.Pd.', 'FA', null, ['ENG', 'SCI']],
            ['Kristianus Mantio, S.Pd.', 'KR', null, ['PJOK']],
            ['Anci, S.Pd.', 'AC', null, ['SBdP']],
            ['Kristina Sucilita, S.Pd.', 'LS', null, ['MAND']],
            ['Eky Sepyanto', 'EK', null, ['MAND', 'MUSIK']],
            ['Cindy Fatika Sari, S.Pd.', 'CF', null, ['BI']],
            ['Fransiskus P. S.Fil', 'FR', null, ['AGAMA']],
        ];

        foreach ($data as [$nama, $kode, $wali, $mapel]) {
            $user = User::create([
                'name' => $nama,
                'email' => strtolower($kode) . '@sdplus.test',
                'password' => 'password',
            ]);

            $user->assignRole('guru');

            $guru = $user->guru()->create(['kode_guru' => $kode]);
            $guru->mataPelajaran()->sync($mapelIds->only($mapel)->values());

            if ($wali) {
                $ta->kelas()->where('nama_kelas', $wali)->update(['wali_kelas_id' => $guru->id]);
            }
        }

        Guru::where('kode_guru', 'A')->first()->user->assignRole('waka_kurikulum', 'bendahara');
    }
}
