<?php

namespace App\Domains\JadwalGuru\Services;

use App\Domains\JadwalGuru\Models\KisiKisiTugas;
use App\Domains\JadwalGuru\Models\WaktuPelajaran;
use App\Models\Guru;
use App\Models\JenisTugas;
use App\Models\Kelas;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class KisiKisiTugasService
{
    public function store(int $tahunAjaranId, array $data): KisiKisiTugas
    {
        return $this->persistWithDuplicateHandling(function () use ($tahunAjaranId, $data) {
            return DB::transaction(function () use ($tahunAjaranId, $data) {
                $this->assertGuruAktif((int) $data['guru_id']);
                $jenisTugas = $this->resolveJenisTugas($data['jenis_tugas']);
                $isUtama = $jenisTugas->kode === 'utama';

                $kelasId = $isUtama ? (int) $data['kelas_id'] : null;
                $mapelId = $isUtama ? (int) $data['mapel_id'] : null;
                $jumlahJam = (int) $data['jumlah_jam'];

                if ($isUtama) {
                    $this->assertClassQuota($tahunAjaranId, $kelasId, $jumlahJam);
                }

                return KisiKisiTugas::create([
                    'tahun_ajaran_id' => $tahunAjaranId,
                    'guru_id' => (int) $data['guru_id'],
                    'jenis_tugas_id' => $jenisTugas->id,
                    'kelas_id' => $kelasId,
                    'mapel_id' => $mapelId,
                    'jumlah_jam' => $jumlahJam,
                ]);
            });
        });
    }

    public function update(KisiKisiTugas $kisiKisi, int $tahunAjaranId, array $data): KisiKisiTugas
    {
        return $this->persistWithDuplicateHandling(function () use ($kisiKisi, $tahunAjaranId, $data) {
            return DB::transaction(function () use ($kisiKisi, $tahunAjaranId, $data) {
                $kisiKisi = KisiKisiTugas::query()
                    ->where('tahun_ajaran_id', $tahunAjaranId)
                    ->lockForUpdate()
                    ->findOrFail($kisiKisi->id);

                $this->assertGuruAktif((int) $data['guru_id']);
                $jenisTugas = $this->resolveJenisTugas($data['jenis_tugas']);
                $isUtama = $jenisTugas->kode === 'utama';
                $kelasId = $isUtama ? (int) $data['kelas_id'] : null;
                $mapelId = $isUtama ? (int) $data['mapel_id'] : null;
                $jumlahJam = (int) $data['jumlah_jam'];

                $jadwalTerpasang = $kisiKisi->jadwalMengajar()->count();
                $identitasBerubah =
                    (int) $kisiKisi->guru_id !== (int) $data['guru_id'] ||
                    (int) $kisiKisi->jenis_tugas_id !== (int) $jenisTugas->id ||
                    ($kisiKisi->kelas_id === null ? null : (int) $kisiKisi->kelas_id) !== $kelasId ||
                    ($kisiKisi->mapel_id === null ? null : (int) $kisiKisi->mapel_id) !== $mapelId;

                if ($jadwalTerpasang > 0 && $identitasBerubah) {
                    throw ValidationException::withMessages([
                        'jenis_tugas' => 'Identitas alokasi tidak dapat diubah karena sudah memiliki jadwal aktual. Tarik jadwal terkait terlebih dahulu.',
                    ]);
                }

                if ($jumlahJam < $jadwalTerpasang) {
                    throw ValidationException::withMessages([
                        'jumlah_jam' => "Jumlah jam tidak boleh lebih kecil dari {$jadwalTerpasang} slot yang sudah dijadwalkan.",
                    ]);
                }

                if ($isUtama) {
                    $this->assertClassQuota($tahunAjaranId, $kelasId, $jumlahJam, $kisiKisi->id);
                }

                $kisiKisi->fill([
                    'guru_id' => (int) $data['guru_id'],
                    'jenis_tugas_id' => $jenisTugas->id,
                    'kelas_id' => $kelasId,
                    'mapel_id' => $mapelId,
                    'jumlah_jam' => $jumlahJam,
                ])->save();

                return $kisiKisi->refresh();
            });
        });
    }

    public function delete(KisiKisiTugas $kisiKisi, int $tahunAjaranId): void
    {
        DB::transaction(function () use ($kisiKisi, $tahunAjaranId) {
            $kisiKisi = KisiKisiTugas::query()
                ->where('tahun_ajaran_id', $tahunAjaranId)
                ->lockForUpdate()
                ->findOrFail($kisiKisi->id);

            if ($kisiKisi->jadwalMengajar()->exists()) {
                throw ValidationException::withMessages([
                    'allocation' => 'Alokasi tidak dapat dihapus karena sudah memiliki jadwal aktual. Tarik jadwalnya terlebih dahulu.',
                ]);
            }

            $kisiKisi->delete();
        });
    }


    private function assertGuruAktif(int $guruId): void
    {
        $guruAktif = Guru::query()
            ->where('status_aktif', true)
            ->whereKey($guruId)
            ->exists();

        if (!$guruAktif) {
            throw ValidationException::withMessages([
                'guru_id' => 'Guru tidak ditemukan atau statusnya tidak aktif.',
            ]);
        }
    }

    private function resolveJenisTugas(string $kode): JenisTugas
    {
        $jenisTugas = JenisTugas::byKode($kode);

        if (!$jenisTugas) {
            throw ValidationException::withMessages([
                'jenis_tugas' => 'Jenis tugas yang dipilih tidak ditemukan pada master.',
            ]);
        }

        return $jenisTugas;
    }

    private function assertClassQuota(int $tahunAjaranId, int $kelasId, int $jamBaru, ?int $ignoreId = null): void
    {
        $kelas = Kelas::query()
            ->where('tahun_ajaran_id', $tahunAjaranId)
            ->findOrFail($kelasId);

        $slotsKbm = WaktuPelajaran::query()
            ->where('tahun_ajaran_id', $tahunAjaranId)
            ->where('jenis_kegiatan', 'kbm')
            ->where('jenjang_min', '<=', $kelas->jenjang)
            ->where('jenjang_max', '>=', $kelas->jenjang)
            ->withCount('hariBerlaku')
            ->get();

        $kuota = (int) $slotsKbm->sum('hari_berlaku_count');

        $teralokasi = KisiKisiTugas::query()
            ->where('tahun_ajaran_id', $tahunAjaranId)
            ->where('kelas_id', $kelasId)
            ->whereHas('jenisTugas', fn ($query) => $query->where('kode', 'utama'))
            ->when($ignoreId, fn ($query) => $query->where('id', '<>', $ignoreId))
            ->sum('jumlah_jam');

        if ($kuota < 1) {
            throw ValidationException::withMessages([
                'kelas_id' => 'Kuota kelas tidak dapat dihitung. Periksa master waktu pelajaran dan hari berlakunya.',
            ]);
        }

        if (((int) $teralokasi + $jamBaru) > $kuota) {
            $sisa = max(0, $kuota - (int) $teralokasi);
            throw ValidationException::withMessages([
                'jumlah_jam' => "Alokasi untuk {$kelas->nama_kelas} melebihi kuota {$kuota} jam. Sisa kuota yang tersedia {$sisa} jam.",
            ]);
        }
    }

    private function persistWithDuplicateHandling(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (QueryException $exception) {
            // PostgreSQL unique_violation. Constraint ini mencegah duplikasi
            // guru + jenis tugas + kelas + mapel pada tahun ajaran yang sama.
            if ($exception->getCode() === '23505' || str_contains($exception->getMessage(), 'unique_kisi_tugas')) {
                throw ValidationException::withMessages([
                    'allocation' => 'Alokasi dengan guru, jenis tugas, kelas, dan mata pelajaran tersebut sudah ada.',
                ]);
            }

            throw $exception;
        }
    }
}
