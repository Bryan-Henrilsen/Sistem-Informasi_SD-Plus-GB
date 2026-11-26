<?php

namespace Database\Seeders;

use App\Domains\JadwalGuru\Models\JadwalMengajar;
use App\Domains\JadwalGuru\Models\KisiKisiTugas;
use App\Domains\JadwalGuru\Models\WaktuPelajaran;
use App\Domains\JadwalGuru\Services\JadwalService;
use App\Models\Guru;
use App\Models\JenisTugas;
use App\Models\Kelas;
use App\Models\TahunAjaran;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

// DATA CONTOH untuk pengembangan dan demo — bukan jadwal sekolah yang sebenarnya (Ringkasan v1.11 #52).
// Mengisi kisi-kisi tugas dan jadwal mengajar tahun ajaran aktif supaya Hasil Jadwal dan Jadwal Saya (Jurnal Guru) tidak kosong.
// Penempatan memakai JadwalService sehingga seluruh aturan anti-bentrok tetap berlaku.
// Jalankan setelah DatabaseSeeder: php artisan db:seed --class=DataContohJadwalGuruSeeder
class DataContohJadwalGuruSeeder extends Seeder
{
    private const MAPEL_PER_KELAS = 4;

    private const JAM_PER_MAPEL = 2;

    public function __construct(private JadwalService $jadwal) {}

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command->warn('Data contoh tidak boleh dijalankan di produksi.');

            return;
        }

        $tahunAjaran = TahunAjaran::aktif()->first();
        if (! $tahunAjaran) {
            $this->command->warn('Belum ada tahun ajaran aktif.');

            return;
        }

        if (KisiKisiTugas::where('tahun_ajaran_id', $tahunAjaran->id)->exists()) {
            $this->command->warn('Kisi-kisi tahun ajaran aktif sudah terisi; seeder dilewati supaya data tidak dobel.');

            return;
        }

        $slots = WaktuPelajaran::where('tahun_ajaran_id', $tahunAjaran->id)->with('hariBerlaku')->orderBy('urutan')->get();
        $kelas = Kelas::where('tahun_ajaran_id', $tahunAjaran->id)->orderBy('jenjang')->orderBy('nama_kelas')->get();
        $guru = Guru::where('status_aktif', true)->with('mataPelajaran')->orderBy('id')->get();

        if ($slots->isEmpty() || $kelas->isEmpty() || $guru->isEmpty()) {
            $this->command->warn('Waktu pelajaran, kelas, atau guru belum ada. Jalankan DatabaseSeeder dulu.');

            return;
        }

        $jenis = JenisTugas::pluck('id', 'kode');
        $bebanGuru = $guru->mapWithKeys(fn (Guru $g) => [$g->id => 0])->all();

        DB::transaction(function () use ($tahunAjaran, $slots, $kelas, $guru, $jenis, &$bebanGuru) {
            // 1. Mengajar utama: setiap kelas 4 mapel × 2 jam, guru dipilih dari mapel yang diampunya dengan beban paling ringan
            foreach ($kelas as $i => $k) {
                $mapelGuru = $guru->flatMap(fn (Guru $g) => $g->mataPelajaran->map(fn ($m) => ['mapel_id' => $m->id, 'guru_id' => $g->id]));
                $mapelKelas = $mapelGuru->pluck('mapel_id')->unique()->values()->slice($i % 3, self::MAPEL_PER_KELAS);

                foreach ($mapelKelas as $mapelId) {
                    $guruId = $mapelGuru->where('mapel_id', $mapelId)->pluck('guru_id')
                        ->sortBy(fn (int $id) => $bebanGuru[$id])->first();

                    $kisi = KisiKisiTugas::create([
                        'tahun_ajaran_id' => $tahunAjaran->id, 'guru_id' => $guruId, 'jenis_tugas_id' => $jenis['utama'],
                        'kelas_id' => $k->id, 'mapel_id' => $mapelId, 'jumlah_jam' => self::JAM_PER_MAPEL,
                    ]);
                    $bebanGuru[$guruId] += $this->tempatkan($kisi, $this->slotKbm($slots, $k->jenjang), $i);
                }
            }

            // 2. Damping kelas 1–2: menempel pada jadwal utama yang sudah ada
            $pendamping = $guru->sortBy(fn (Guru $g) => $bebanGuru[$g->id])->first();
            $kisiDamping = KisiKisiTugas::create([
                'tahun_ajaran_id' => $tahunAjaran->id, 'guru_id' => $pendamping->id, 'jenis_tugas_id' => $jenis['damping'], 'jumlah_jam' => 4,
            ]);
            $utamaKelasKecil = JadwalMengajar::whereIn('kelas_id', $kelas->where('jenjang', '<=', 2)->pluck('id'))
                ->whereNull('parent_jadwal_id')->orderBy('hari')->orderBy('waktu_pelajaran_id')->get();
            $terpasang = 0;
            foreach ($utamaKelasKecil as $utama) {
                if ($terpasang >= $kisiDamping->jumlah_jam) {
                    break;
                }
                $terpasang += $this->coba(fn () => $this->jadwal->assignJadwal($kisiDamping->id, $utama->waktu_pelajaran_id, $utama->hari, $utama->id));
            }

            // 3. Piket: tiga guru dengan beban paling ringan, masing-masing 2 jam di slot istirahat
            $slotIstirahat = $slots->where('jenis_kegiatan', 'istirahat')->values();
            foreach ($guru->sortBy(fn (Guru $g) => $bebanGuru[$g->id])->take(3)->values() as $i => $g) {
                $kisiPiket = KisiKisiTugas::create([
                    'tahun_ajaran_id' => $tahunAjaran->id, 'guru_id' => $g->id, 'jenis_tugas_id' => $jenis['piket'], 'jumlah_jam' => 2,
                ]);
                $this->tempatkan($kisiPiket, $slotIstirahat, $i);
            }
        });

        $this->command->info(sprintf('%d kisi-kisi dan %d jadwal contoh dibuat.',
            KisiKisiTugas::where('tahun_ajaran_id', $tahunAjaran->id)->count(),
            JadwalMengajar::whereHas('kisiKisiTugas', fn ($q) => $q->where('tahun_ajaran_id', $tahunAjaran->id))->count()));
    }

    // Slot KBM yang berlaku untuk jenjang kelas
    private function slotKbm(Collection $slots, int $jenjang): Collection
    {
        return $slots->filter(fn (WaktuPelajaran $s) => $s->jenis_kegiatan === 'kbm' && $s->jenjang_min <= $jenjang && $s->jenjang_max >= $jenjang)->values();
    }

    // Tempatkan sebanyak jumlah_jam, mulai dari hari & slot yang digeser per kelas supaya jadwal tersebar; hasilnya jumlah yang terpasang
    private function tempatkan(KisiKisiTugas $kisi, Collection $slots, int $geser): int
    {
        $terpasang = 0;

        foreach (range(0, 4) as $d) {
            $hari = ($d + $geser) % 5 + 1;
            $kandidat = $slots->filter(fn (WaktuPelajaran $s) => $s->hariBerlaku->contains('hari', $hari))->values();
            $kandidat = $kandidat->slice(($geser * 2) % max($kandidat->count(), 1))->concat($kandidat)->unique('id');

            foreach ($kandidat as $slot) {
                if ($this->coba(fn () => $this->jadwal->assignJadwal($kisi->id, $slot->id, $hari))) {
                    $terpasang++;

                    break; // satu jam per hari supaya mapel tersebar dalam seminggu
                }
            }

            if ($terpasang >= $kisi->jumlah_jam) {
                break;
            }
        }

        return $terpasang;
    }

    private function coba(callable $tempatkan): int
    {
        try {
            $tempatkan();

            return 1;
        } catch (RuntimeException) {
            return 0; // bentrok / slot tidak berlaku: coba slot berikutnya
        }
    }
}
