<?php

namespace App\Domains\JadwalGuru\Services;

use App\Domains\JadwalGuru\Models\JadwalMengajar;
use App\Domains\JadwalGuru\Models\KisiKisiTugas;
use App\Domains\JadwalGuru\Models\WaktuPelajaran;
use App\Models\Kelas;
use App\Models\TahunAjaran;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class JadwalService
{
    /**
     * Menempatkan satu unit alokasi ke slot jadwal.
     * Damping harus mengirim parentJadwalId dari jadwal utama yang dituju.
     */
    public function assignJadwal(
        int $kisiKisiTugasId,
        int $waktuPelajaranId,
        int $hari,
        ?int $parentJadwalId = null
    ): JadwalMengajar {
        return DB::transaction(function () use (
            $kisiKisiTugasId,
            $waktuPelajaranId,
            $hari,
            $parentJadwalId
        ): JadwalMengajar {
            $tahunAjaran = TahunAjaran::aktif()->firstOrFail();

            // Lock row kisi-kisi agar dua request bersamaan tidak melampaui jumlah_jam.
            $kisiKisi = KisiKisiTugas::query()
                ->with('jenisTugas')
                ->where('tahun_ajaran_id', $tahunAjaran->id)
                ->lockForUpdate()
                ->findOrFail($kisiKisiTugasId);

            $slot = WaktuPelajaran::query()
                ->with('hariBerlaku')
                ->where('tahun_ajaran_id', $tahunAjaran->id)
                ->lockForUpdate()
                ->findOrFail($waktuPelajaranId);

            $kodeTugas = $kisiKisi->jenisTugas?->kode;
            if (!in_array($kodeTugas, ['utama', 'damping', 'piket'], true)) {
                throw new RuntimeException('Jenis tugas tidak dikenali.');
            }

            if (!$slot->hariBerlaku->contains('hari', $hari)) {
                throw new RuntimeException('Slot waktu tersebut tidak berlaku pada hari yang dipilih.');
            }

            $jumlahTerpasang = $kisiKisi->jadwalMengajar()->count();
            if ($jumlahTerpasang >= (int) $kisiKisi->jumlah_jam) {
                throw new RuntimeException('Sisa alokasi jam untuk tugas ini sudah habis.');
            }

            // Satu guru hanya boleh memegang satu tugas pada waktu dan hari yang sama.
            $bentrokGuru = JadwalMengajar::query()
                ->where('guru_id', $kisiKisi->guru_id)
                ->where('hari', $hari)
                ->where('waktu_pelajaran_id', $waktuPelajaranId)
                ->exists();

            if ($bentrokGuru) {
                throw new RuntimeException('Bentrok: guru ini sudah memiliki tugas pada slot waktu tersebut.');
            }

            $parentJadwalIdToSave = null;

            if ($kodeTugas === 'utama') {
                if (!$kisiKisi->kelas_id || !$kisiKisi->mapel_id) {
                    throw new RuntimeException('Tugas utama wajib memiliki kelas dan mata pelajaran.');
                }

                if ($parentJadwalId !== null) {
                    throw new RuntimeException('Jadwal utama tidak boleh memiliki parent jadwal.');
                }

                if ($slot->jenis_kegiatan !== 'kbm') {
                    throw new RuntimeException('Guru utama hanya dapat ditempatkan pada slot KBM.');
                }

                $kelas = Kelas::query()
                    ->where('tahun_ajaran_id', $tahunAjaran->id)
                    ->findOrFail($kisiKisi->kelas_id);

                if ($kelas->jenjang < $slot->jenjang_min || $kelas->jenjang > $slot->jenjang_max) {
                    throw new RuntimeException('Slot waktu tidak berlaku untuk jenjang kelas ini.');
                }

                $bentrokKelas = JadwalMengajar::query()
                    ->where('kelas_id', $kelas->id)
                    ->where('hari', $hari)
                    ->where('waktu_pelajaran_id', $waktuPelajaranId)
                    ->whereHas('jenisTugas', fn ($query) => $query->where('kode', 'utama'))
                    ->exists();

                if ($bentrokKelas) {
                    throw new RuntimeException('Bentrok: kelas ini sudah memiliki guru utama pada slot tersebut.');
                }
            }

            if ($kodeTugas === 'damping') {
                if ($kisiKisi->kelas_id !== null || $kisiKisi->mapel_id !== null) {
                    throw new RuntimeException('Alokasi damping tidak boleh memiliki kelas atau mata pelajaran pada kisi-kisi.');
                }

                if ($parentJadwalId === null) {
                    throw new RuntimeException('Pilih slot guru utama yang akan ditempeli guru damping.');
                }

                // Parent wajib merupakan jadwal utama pada tahun ajaran aktif.
                $jadwalUtama = JadwalMengajar::query()
                    ->with(['jenisTugas', 'kelas'])
                    ->whereHas('kisiKisiTugas', fn ($query) => $query->where('tahun_ajaran_id', $tahunAjaran->id))
                    ->lockForUpdate()
                    ->findOrFail($parentJadwalId);

                if ($jadwalUtama->jenisTugas?->kode !== 'utama') {
                    throw new RuntimeException('Guru damping hanya dapat ditempelkan pada jadwal guru utama.');
                }

                if ((int) $jadwalUtama->hari !== $hari || (int) $jadwalUtama->waktu_pelajaran_id !== $waktuPelajaranId) {
                    throw new RuntimeException('Slot damping harus sama persis dengan hari dan waktu guru utama.');
                }

                if (!$jadwalUtama->kelas_id) {
                    throw new RuntimeException('Jadwal utama tidak memiliki kelas yang valid.');
                }

                $kelasUtama = Kelas::query()
                    ->where('tahun_ajaran_id', $tahunAjaran->id)
                    ->findOrFail($jadwalUtama->kelas_id);

                // PRD: damping saat ini hanya digunakan untuk kelas jenjang 1–2.
                if ($kelasUtama->jenjang > 2) {
                    throw new RuntimeException('Guru damping hanya dapat ditempatkan pada kelas jenjang 1–2.');
                }

                $dampingSudahAda = JadwalMengajar::query()
                    ->where('parent_jadwal_id', $jadwalUtama->id)
                    ->whereHas('jenisTugas', fn ($query) => $query->where('kode', 'damping'))
                    ->exists();

                if ($dampingSudahAda) {
                    throw new RuntimeException('Slot guru utama ini sudah memiliki guru damping.');
                }

                $parentJadwalIdToSave = $jadwalUtama->id;
            }

            if ($kodeTugas === 'piket') {
                if ($kisiKisi->kelas_id !== null || $kisiKisi->mapel_id !== null) {
                    throw new RuntimeException('Alokasi piket harus tanpa kelas dan mata pelajaran.');
                }

                if ($parentJadwalId !== null) {
                    throw new RuntimeException('Jadwal piket tidak boleh memiliki parent jadwal.');
                }

                // PRD tidak membatasi piket hanya pada slot KBM. Slot harus tetap
                // berlaku pada hari yang dipilih, seperti validasi umum di atas.
            }

            return JadwalMengajar::create([
                'kisi_kisi_tugas_id' => $kisiKisi->id,
                'waktu_pelajaran_id' => $slot->id,
                'hari' => $hari,
                'parent_jadwal_id' => $parentJadwalIdToSave,
            ]);
        });
    }

    /**
     * Menghapus jadwal. Jika jadwal utama dihapus, child damping
     * ikut terhapus melalui ON DELETE CASCADE pada parent_jadwal_id.
     */
    public function removeJadwal(int $jadwalId): bool
    {
        return DB::transaction(function () use ($jadwalId): bool {
            $tahunAjaran = TahunAjaran::aktif()->firstOrFail();

            $jadwal = JadwalMengajar::query()
                ->whereHas('kisiKisiTugas', fn ($query) => $query->where('tahun_ajaran_id', $tahunAjaran->id))
                ->lockForUpdate()
                ->findOrFail($jadwalId);

            $jadwal->delete();

            return true;
        });
    }
}
