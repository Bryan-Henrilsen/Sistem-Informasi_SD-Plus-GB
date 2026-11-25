<?php

namespace App\Domains\JurnalGuru\Services;

use App\Domains\JadwalGuru\Models\JadwalMengajar;
use App\Domains\LesEkskul\Models\Ekstrakurikuler;
use App\Domains\LesEkskul\Models\JadwalEkskul;
use App\Models\Guru;
use App\Models\Kelas;
use App\Models\TahunAjaran;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

// Jadwal pribadi guru (PRD B.2 FR-1, Ringkasan v1.11 #51). Hanya membaca: jadwal_mengajar (modul Jadwal Guru, Pengembang 2),
// penugasan pengajar/pendamping ekskul, dan les tambahan kelas yang ia pegang sebagai wali
class JadwalSayaService
{
    public const NAMA_HARI = [1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu'];

    public function untuk(Guru $guru): array
    {
        $tahunAjaran = TahunAjaran::aktif()->first();

        return [
            'tahun_ajaran' => $tahunAjaran?->nama,
            'hari' => $tahunAjaran ? $this->jadwalMengajar($guru, $tahunAjaran) : [],
            'ekskul' => $tahunAjaran ? $this->ekskul($guru, $tahunAjaran) : [],
            'les' => $tahunAjaran ? $this->les($guru, $tahunAjaran) : [],
        ];
    }

    // Per hari, urut jam: mengajar utama, damping, dan piket
    private function jadwalMengajar(Guru $guru, TahunAjaran $tahunAjaran): array
    {
        return JadwalMengajar::query()
            ->where('guru_id', $guru->id)
            ->whereHas('kisiKisiTugas', fn (Builder $q) => $q->where('tahun_ajaran_id', $tahunAjaran->id))
            ->with(['waktuPelajaran', 'jenisTugas', 'kelas', 'kisiKisiTugas.mataPelajaran', 'jadwalUtama.guru.user:id,name', 'jadwalUtama.kisiKisiTugas.mataPelajaran'])
            ->get()
            ->sortBy(fn (JadwalMengajar $j) => sprintf('%d-%04d', $j->hari, $j->waktuPelajaran->urutan))
            ->groupBy('hari')
            ->map(fn (Collection $daftar, int $hari) => [
                'hari' => $hari,
                'nama' => self::NAMA_HARI[$hari] ?? "Hari {$hari}",
                'jadwal' => $daftar->map(fn (JadwalMengajar $j) => [
                    'id' => $j->id,
                    'jam_ke' => $j->waktuPelajaran->jam_ke,
                    'slot' => $j->waktuPelajaran->nama,
                    'jam_mulai' => substr((string) $j->waktuPelajaran->jam_mulai, 0, 5),
                    'jam_selesai' => substr((string) $j->waktuPelajaran->jam_selesai, 0, 5),
                    'jenis' => $j->jenisTugas?->kode, // utama / damping / piket
                    'jenis_nama' => $j->jenisTugas?->nama,
                    'kelas' => $j->kelas?->nama_lengkap,
                    // Damping memakai mapel dan guru utama dari jadwal induknya
                    'mapel' => ($j->kisiKisiTugas?->mataPelajaran ?? $j->jadwalUtama?->kisiKisiTugas?->mataPelajaran)?->nama,
                    'guru_utama' => $j->jadwalUtama?->guru?->user?->name,
                ])->values(),
            ])
            ->values()
            ->all();
    }

    // Ekskul yang diajar (pengajar internal, penugasan masih berjalan) atau didampingi guru ini, beserta jadwalnya
    private function ekskul(Guru $guru, TahunAjaran $tahunAjaran): array
    {
        return Ekstrakurikuler::query()
            ->where('tahun_ajaran_id', $tahunAjaran->id)
            ->where(fn (Builder $q) => $q
                ->whereHas('pengajar', fn (Builder $p) => $p->where('pengajar_ekskul.user_id', $guru->user_id))
                ->orWhereHas('pendamping', fn (Builder $p) => $p->where('users.id', $guru->user_id)))
            ->with(['induk.jadwal', 'jadwal', 'pengajar', 'pendamping'])
            ->orderBy('nama')
            ->get()
            ->map(function (Ekstrakurikuler $e) use ($guru) {
                $jadwal = $e->jadwal->isNotEmpty() ? $e->jadwal : ($e->induk?->jadwal ?? collect());

                return [
                    'id' => $e->id,
                    'nama' => $e->nama,
                    'peran' => $e->pengajar->contains('user_id', $guru->user_id) ? 'Pengajar' : 'Pendamping',
                    'jadwal' => $jadwal->sortBy(['hari', 'jam_mulai'])->map(fn (JadwalEkskul $j) => [
                        'hari' => self::NAMA_HARI[$j->hari] ?? "Hari {$j->hari}",
                        'jam' => substr((string) $j->jam_mulai, 0, 5).'–'.substr((string) $j->jam_selesai, 0, 5),
                        'jenjang' => $j->jenjang_min ? "kelas {$j->jenjang_min}–{$j->jenjang_max}" : null,
                        'lokasi' => $j->lokasi,
                    ])->values(),
                ];
            })
            ->all();
    }

    // Les tambahan kelas yang wali kelasnya guru ini (pengajar les = wali kelas, FR-16c)
    private function les(Guru $guru, TahunAjaran $tahunAjaran): array
    {
        return Kelas::where('tahun_ajaran_id', $tahunAjaran->id)
            ->where('wali_kelas_id', $guru->id)
            ->whereHas('lesTambahan')
            ->get()
            ->map(fn (Kelas $k) => ['kelas' => $k->nama_lengkap])
            ->all();
    }
}
