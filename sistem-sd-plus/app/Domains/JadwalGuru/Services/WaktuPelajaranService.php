<?php

namespace App\Domains\JadwalGuru\Services;

use App\Domains\JadwalGuru\Models\WaktuPelajaran;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WaktuPelajaranService
{
    /**
     * Membuat slot waktu beserta hari berlakunya dalam satu transaksi.
     */
    public function store(int $tahunAjaranId, array $data): WaktuPelajaran
    {
        return DB::transaction(function () use ($tahunAjaranId, $data) {
            $hari = $data['hari'];
            unset($data['hari']);

            $slot = WaktuPelajaran::create([
                ...$data,
                'tahun_ajaran_id' => $tahunAjaranId,
            ]);

            $this->syncHari($slot, $hari);

            return $slot->load('hariBerlaku');
        });
    }

    /**
     * Slot yang sudah digunakan jadwal aktual hanya boleh diubah jamnya.
     */
    public function update(WaktuPelajaran $slot, array $data): WaktuPelajaran
    {
        return DB::transaction(function () use ($slot, $data) {
            $slot = WaktuPelajaran::query()
                ->whereKey($slot->id)
                ->lockForUpdate()
                ->firstOrFail();

            $hariBaru = collect($data['hari'])
                ->map(fn ($hari) => (int) $hari)
                ->unique()
                ->sort()
                ->values()
                ->all();

            $hariSaatIni = $slot->hariBerlaku()
                ->pluck('hari')
                ->map(fn ($hari) => (int) $hari)
                ->sort()
                ->values()
                ->all();

            $digunakan = $slot->jadwalMengajar()->exists();

            if ($digunakan) {
                $fieldTerkunci = [
                    'urutan',
                    'jam_ke',
                    'nama',
                    'jenis_kegiatan',
                    'jenjang_min',
                    'jenjang_max',
                ];

                foreach ($fieldTerkunci as $field) {
                    $nilaiBaru = $data[$field] ?? null;
                    $nilaiLama = $slot->{$field};

                    if ($nilaiLama !== null && is_numeric($nilaiLama)) {
                        $nilaiLama = (int) $nilaiLama;
                    }
                    if ($nilaiBaru !== null && is_numeric($nilaiBaru)) {
                        $nilaiBaru = (int) $nilaiBaru;
                    }

                    if ($nilaiBaru !== $nilaiLama) {
                        throw ValidationException::withMessages([
                            'slot' => 'Slot ini sudah digunakan dalam jadwal aktual. Hanya jam mulai dan jam selesai yang boleh diubah.',
                        ]);
                    }
                }

                if ($hariBaru !== $hariSaatIni) {
                    throw ValidationException::withMessages([
                        'hari' => 'Hari berlaku tidak dapat diubah karena slot ini sudah digunakan dalam jadwal aktual.',
                    ]);
                }

                $slot->update([
                    'jam_mulai' => $data['jam_mulai'],
                    'jam_selesai' => $data['jam_selesai'],
                ]);

                return $slot->fresh('hariBerlaku');
            }

            $hari = $data['hari'];
            unset($data['hari']);

            $slot->update($data);
            $this->syncHari($slot, $hari);

            return $slot->fresh('hariBerlaku');
        });
    }

    /**
     * Slot yang sudah dipakai tidak boleh dihapus.
     */
    public function delete(WaktuPelajaran $slot): void
    {
        DB::transaction(function () use ($slot) {
            $slot = WaktuPelajaran::query()
                ->whereKey($slot->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($slot->jadwalMengajar()->exists()) {
                throw ValidationException::withMessages([
                    'slot' => 'Slot tidak dapat dihapus karena sudah digunakan dalam jadwal aktual. Sesuai ketentuan, slot hanya boleh diubah jamnya.',
                ]);
            }

            // Relasi waktu_pelajaran_hari ikut terhapus melalui FK cascade.
            $slot->delete();
        });
    }

    private function syncHari(WaktuPelajaran $slot, array $hari): void
    {
        $slot->hariBerlaku()->delete();

        $rows = collect($hari)
            ->map(fn ($nilai) => [
                'waktu_pelajaran_id' => $slot->id,
                'hari' => (int) $nilai,
            ])
            ->values()
            ->all();

        if ($rows !== []) {
            DB::table('waktu_pelajaran_hari')->insert($rows);
        }
    }
}
