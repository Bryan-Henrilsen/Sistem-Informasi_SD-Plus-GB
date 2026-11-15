<?php

namespace Database\Seeders;

use App\Domains\LesEkskul\Enums\JenisPengajar;
use App\Domains\LesEkskul\Enums\TipeKegiatan;
use App\Domains\LesEkskul\Models\SkemaHonor;
use Illuminate\Database\Seeder;

// Skema honor awal dari rekap honor sekolah Agustus 2026 (Ringkasan v1.11 #33). Kepala Sekolah dapat mengubahnya
// di halaman Skema Honor; perubahan menambah baris baru. Aman dijalankan ulang: baris awal tidak ditimpa.
class SkemaHonorSeeder extends Seeder
{
    public function run(): void
    {
        $pengelolaEkskul = ['persen_penanggung_jawab' => 7, 'persen_bendahara' => 3.2, 'persen_admin_tu' => 3.2, 'persen_admin_it' => 1.6];

        $skema = [
            // Pengajar internal 65% = 60% + 5% porsi pendamping, karena kegiatan berpengajar internal tidak memakai pendamping
            [TipeKegiatan::Ekstrakurikuler, JenisPengajar::Internal, ['persen_pengajar' => 65, 'persen_guru_pendamping' => null, 'persen_kas_sekolah' => 20, ...$pengelolaEkskul]],
            [TipeKegiatan::Ekstrakurikuler, JenisPengajar::Eksternal, ['persen_pengajar' => 60, 'persen_guru_pendamping' => 5, 'persen_kas_sekolah' => 20, ...$pengelolaEkskul]],
            [TipeKegiatan::LesTambahan, null, [
                'persen_pengajar' => 60, 'persen_yayasan' => 30, 'persen_kas_sekolah' => 6.85,
                'persen_penanggung_jawab' => 1, 'persen_bendahara' => 0.75, 'persen_admin_tu' => 0.7, 'persen_admin_it' => 0.7,
            ]],
        ];

        foreach ($skema as [$tipe, $jenis, $persen]) {
            SkemaHonor::firstOrCreate(
                ['tipe_kegiatan' => $tipe, 'jenis_pengajar' => $jenis, 'berlaku_sejak' => '2026-07-01'],
                $persen,
            );
        }
    }
}
