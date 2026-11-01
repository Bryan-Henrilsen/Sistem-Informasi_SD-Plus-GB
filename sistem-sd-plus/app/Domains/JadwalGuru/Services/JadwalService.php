<?php

namespace App\Domains\JadwalGuru\Services;

use App\Domains\JadwalGuru\Models\JadwalMengajar;
use App\Domains\JadwalGuru\Models\KisiKisiTugas;
use Illuminate\Support\Facades\DB;
use Exception;

class JadwalService
{
    /**
     * Memproses penempatan kartu tugas (Drag & Drop) ke dalam grid jadwal
     */
    public function assignJadwal(int $kisiKisiTugasId, int $waktuPelajaranId, int $hari)
    {
        return DB::transaction(function () use ($kisiKisiTugasId, $waktuPelajaranId, $hari) {
            $kisiKisi = KisiKisiTugas::with('jenisTugas')->findOrFail($kisiKisiTugasId);
            $kodeTugas = $kisiKisi->jenisTugas->kode;

            // 1. Validasi Sisa Kuota (Kartu tidak boleh ditarik jika kuota habis)
            if ($kisiKisi->sisa_jam <= 0) {
                throw new Exception("Sisa alokasi jam untuk tugas ini sudah habis.");
            }

            // 2. Anti-Bentrok Mutlak: 1 Guru hanya boleh ada di 1 tempat pada slot waktu & hari yang sama
            $bentrokGuru = JadwalMengajar::where('guru_id', $kisiKisi->guru_id)
                ->where('hari', $hari)
                ->where('waktu_pelajaran_id', $waktuPelajaranId)
                ->exists();

            if ($bentrokGuru) {
                throw new Exception("Bentrok! Guru ini sudah memiliki jadwal mengajar/piket di slot waktu tersebut.");
            }

            $parentJadwalId = null;

            // 3. Aturan Khusus Berdasarkan Jenis Tugas (Utama / Damping)
            if ($kodeTugas === 'utama') {
                // Anti-Bentrok Kelas: Kelas hanya boleh diisi 1 guru UTAMA di slot waktu yang sama
                $bentrokKelas = JadwalMengajar::where('kelas_id', $kisiKisi->kelas_id)
                    ->where('hari', $hari)
                    ->where('waktu_pelajaran_id', $waktuPelajaranId)
                    ->whereHas('jenisTugas', function($q) {
                        $q->where('kode', 'utama');
                    })->exists();

                if ($bentrokKelas) {
                    throw new Exception("Bentrok! Kelas ini sudah memiliki Guru Utama di slot waktu tersebut.");
                }
            } elseif ($kodeTugas === 'damping') {
                // Aturan Damping: Wajib menempel/ditaruh pada slot yang sudah ada Guru Utamanya
                $jadwalUtama = JadwalMengajar::where('kelas_id', $kisiKisi->kelas_id)
                    ->where('hari', $hari)
                    ->where('waktu_pelajaran_id', $waktuPelajaranId)
                    ->whereHas('jenisTugas', function($q) {
                        $q->where('kode', 'utama');
                    })->first();

                if (!$jadwalUtama) {
                    throw new Exception("Guru Damping hanya bisa dimasukkan jika sudah ada Guru Utama di kelas dan jam tersebut.");
                }

                $parentJadwalId = $jadwalUtama->id;
            }

            // 4. Eksekusi Simpan ke Database
            // Catatan: kolom guru_id, kelas_id, dan jenis_tugas_id akan diisi otomatis 
            // oleh Event Saving di Model JadwalMengajar yang sudah kita buat sebelumnya.
            $jadwalBaru = JadwalMengajar::create([
                'kisi_kisi_tugas_id' => $kisiKisi->id,
                'waktu_pelajaran_id' => $waktuPelajaranId,
                'hari'               => $hari,
                'parent_jadwal_id'   => $parentJadwalId,
            ]);

            return $jadwalBaru;
        });
    }

    /**
     * Memproses penghapusan jadwal (Menarik kartu kembali ke sumber)
     */
    public function removeJadwal(int $jadwalId)
    {
        return DB::transaction(function () use ($jadwalId) {
            $jadwal = JadwalMengajar::findOrFail($jadwalId);
            
            // Berkat `$table->cascadeOnDelete()` di migration untuk `parent_jadwal_id`,
            // jika kita menghapus jadwal guru utama, jadwal guru damping yang menempel
            // padanya akan otomatis ikut terhapus dari database. Sangat rapi!
            $jadwal->delete();
            
            return true;
        });
    }
}