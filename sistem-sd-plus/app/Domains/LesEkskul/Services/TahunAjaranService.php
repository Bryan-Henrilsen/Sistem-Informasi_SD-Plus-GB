<?php

namespace App\Domains\LesEkskul\Services;

use App\Domains\LesEkskul\Models\Ekstrakurikuler;
use App\Domains\LesEkskul\Models\PeriodePendaftaran;
use App\Enums\StatusSekolah;
use App\Models\Kelas;
use App\Models\SiswaKelas;
use App\Models\TahunAjaran;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Data master Tahun Ajaran & Semester oleh Kepala Sekolah (Ringkasan v1.11 #45)
class TahunAjaranService
{
    public function daftar(): Collection
    {
        return TahunAjaran::with(['semester' => fn ($q) => $q->orderBy('urutan')])
            ->withCount(['kelas', 'siswaKelas'])
            ->orderByDesc('tanggal_mulai')
            ->get()
            ->map(fn (TahunAjaran $t) => [
                'id' => $t->id,
                'nama' => $t->nama,
                'tanggal_mulai' => $t->tanggal_mulai->toDateString(),
                'tanggal_selesai' => $t->tanggal_selesai->toDateString(),
                'aktif' => $t->status_aktif,
                'semester' => $t->semester->map(fn ($s) => [
                    'urutan' => $s->urutan,
                    'tanggal_mulai' => $s->tanggal_mulai->toDateString(),
                    'tanggal_selesai' => $s->tanggal_selesai->toDateString(),
                ])->values(),
                'jumlah_kelas' => $t->kelas_count,
                'jumlah_siswa' => $t->siswa_kelas_count,
                'tanggal_terkunci' => $t->kelas_count > 0,
                'bisa_dihapus' => ! $t->status_aktif && $this->tidakDipakai($t),
            ]);
    }

    // Usulan tahun ajaran berikutnya: nama berurutan, mulai sehari setelah tahun ajaran terakhir, semester Juli–Des & Jan–akhir
    public function usulanBaru(): array
    {
        $terakhir = TahunAjaran::orderByDesc('tanggal_selesai')->first();
        $mulai = $terakhir ? $terakhir->tanggal_selesai->addDay() : Carbon::create(today()->month >= 7 ? today()->year : today()->year - 1, 7, 1)->toImmutable();
        $selesai = $mulai->addYear()->subDay();

        return [
            'nama' => $mulai->year.'/'.($mulai->year + 1),
            'tanggal_mulai' => $mulai->toDateString(),
            'tanggal_selesai' => $selesai->toDateString(),
            'semester' => [
                ['tanggal_mulai' => $mulai->toDateString(), 'tanggal_selesai' => $mulai->setDate($mulai->year, 12, 31)->toDateString()],
                ['tanggal_mulai' => $mulai->setDate($mulai->year + 1, 1, 1)->toDateString(), 'tanggal_selesai' => $selesai->toDateString()],
            ],
        ];
    }

    // Tambah/ubah tahun ajaran beserta dua semesternya. $data: nama, tanggal_mulai, tanggal_selesai, semester[2]{tanggal_mulai, tanggal_selesai}
    public function simpan(array $data, ?TahunAjaran $tahunAjaran = null): TahunAjaran
    {
        return DB::transaction(function () use ($data, $tahunAjaran) {
            $mulai = Carbon::parse($data['tanggal_mulai'])->toImmutable();
            $selesai = Carbon::parse($data['tanggal_selesai'])->toImmutable();
            $semester = collect($data['semester'])->values()->map(fn ($s) => [Carbon::parse($s['tanggal_mulai'])->toImmutable(), Carbon::parse($s['tanggal_selesai'])->toImmutable()]);

            $this->periksa($data['nama'], $mulai, $selesai, $semester->all(), $tahunAjaran);

            $tahunAjaran ??= new TahunAjaran(['status_aktif' => false]);
            $tahunAjaran->fill(['nama' => $data['nama'], 'tanggal_mulai' => $mulai->toDateString(), 'tanggal_selesai' => $selesai->toDateString()])->save();

            foreach ($semester as $i => [$sMulai, $sSelesai]) {
                $tahunAjaran->semester()->updateOrCreate(
                    ['urutan' => $i + 1],
                    ['nama' => 'Semester '.($i + 1), 'tanggal_mulai' => $sMulai->toDateString(), 'tanggal_selesai' => $sSelesai->toDateString()],
                );
            }

            return $tahunAjaran;
        });
    }

    // Persiapan sebelum diaktifkan: syarat yang belum terpenuhi dan siswa tahun aktif yang belum punya kelas di tahun ini
    public function persiapan(TahunAjaran $tahunAjaran): array
    {
        $aktif = TahunAjaran::aktif()->first();
        $hambatan = [];

        if ($tahunAjaran->semester()->count() < 2) {
            $hambatan[] = 'Semester 1 dan 2 belum lengkap.';
        }
        if (! $tahunAjaran->kelas()->exists()) {
            $hambatan[] = 'Belum ada kelas. Salin kelas dari tahun ajaran lalu di halaman Kelas & Les.';
        }

        $belumBerkelas = $aktif && $aktif->id !== $tahunAjaran->id
            ? SiswaKelas::where('tahun_ajaran_id', $aktif->id)
                ->whereHas('siswa', fn ($q) => $q->where('status_sekolah', StatusSekolah::Aktif))
                ->whereNotIn('siswa_id', SiswaKelas::where('tahun_ajaran_id', $tahunAjaran->id)->select('siswa_id'))
                ->count()
            : 0;

        return ['hambatan' => $hambatan, 'siswa_belum_berkelas' => $belumBerkelas];
    }

    // Pindahkan status aktif ke tahun ajaran ini (indeks unik menjamin hanya satu yang aktif)
    public function aktifkan(TahunAjaran $tahunAjaran): void
    {
        DB::transaction(function () use ($tahunAjaran) {
            TahunAjaran::query()->lockForUpdate()->get();
            $hambatan = $this->persiapan($tahunAjaran)['hambatan'];

            if ($hambatan !== []) {
                throw ValidationException::withMessages(['aktifkan' => implode(' ', $hambatan)]);
            }

            TahunAjaran::whereKeyNot($tahunAjaran->id)->where('status_aktif', true)->update(['status_aktif' => false]);
            $tahunAjaran->update(['status_aktif' => true]);
        });
    }

    public function hapus(TahunAjaran $tahunAjaran): void
    {
        DB::transaction(function () use ($tahunAjaran) {
            if ($tahunAjaran->status_aktif || ! $this->tidakDipakai($tahunAjaran)) {
                throw ValidationException::withMessages([
                    'tahun_ajaran' => "Tahun ajaran {$tahunAjaran->nama} sedang aktif atau sudah punya kelas/kegiatan, jadi tidak bisa dihapus.",
                ]);
            }

            $tahunAjaran->semester()->delete();
            $tahunAjaran->delete();
        });
    }

    private function periksa(string $nama, $mulai, $selesai, array $semester, ?TahunAjaran $tahunAjaran): void
    {
        $salah = [];

        if (! preg_match('/^(\d{4})\/(\d{4})$/', $nama, $m) || (int) $m[2] !== (int) $m[1] + 1) {
            $salah['nama'] = 'Nama tahun ajaran berbentuk 2027/2028 (dua tahun berurutan).';
        } elseif (TahunAjaran::where('nama', $nama)->when($tahunAjaran, fn ($q) => $q->whereKeyNot($tahunAjaran->id))->exists()) {
            $salah['nama'] = "Tahun ajaran {$nama} sudah ada.";
        }

        if ($selesai->lte($mulai)) {
            $salah['tanggal_selesai'] = 'Tanggal selesai harus setelah tanggal mulai.';
        } else {
            // Rentang tidak boleh beririsan: tagihan, honor, dan periode mencari tahun ajaran lewat tanggal
            $beririsan = TahunAjaran::where('tanggal_mulai', '<=', $selesai->toDateString())
                ->where('tanggal_selesai', '>=', $mulai->toDateString())
                ->when($tahunAjaran, fn ($q) => $q->whereKeyNot($tahunAjaran->id))
                ->first();
            if ($beririsan) {
                $salah['tanggal_mulai'] = "Rentang tanggal beririsan dengan tahun ajaran {$beririsan->nama}.";
            }
        }

        if ($tahunAjaran && $tahunAjaran->kelas()->exists()
            && (! $tahunAjaran->tanggal_mulai->equalTo($mulai) || ! $tahunAjaran->tanggal_selesai->equalTo($selesai))) {
            $salah['tanggal_mulai'] = 'Tanggal tahun ajaran tidak bisa diubah karena sudah punya kelas. Tanggal semester tetap bisa diubah.';
        }

        [[$m1, $s1], [$m2, $s2]] = $semester;
        if ($m1->lt($mulai) || $s2->gt($selesai) || $s1->lt($m1) || $s2->lt($m2)) {
            $salah['semester'] = 'Tanggal semester harus di dalam tahun ajaran, dengan tanggal mulai sebelum tanggal selesai.';
        } elseif ($m2->lte($s1)) {
            $salah['semester'] = 'Semester 2 harus dimulai setelah Semester 1 selesai.';
        }

        if ($salah !== []) {
            throw ValidationException::withMessages($salah);
        }
    }

    private function tidakDipakai(TahunAjaran $tahunAjaran): bool
    {
        return ! Kelas::withTrashed()->where('tahun_ajaran_id', $tahunAjaran->id)->exists()
            && ! Ekstrakurikuler::where('tahun_ajaran_id', $tahunAjaran->id)->exists()
            && ! PeriodePendaftaran::where('tahun_ajaran_id', $tahunAjaran->id)->exists()
            && ! DB::table('honor_flat_pengaturan')->where('tahun_ajaran_id', $tahunAjaran->id)->exists()
            // Modul Jadwal Guru: waktu pelajaran & kisi-kisi tugas per tahun ajaran
            && ! DB::table('waktu_pelajaran')->where('tahun_ajaran_id', $tahunAjaran->id)->exists()
            && ! DB::table('kisi_kisi_tugas')->where('tahun_ajaran_id', $tahunAjaran->id)->exists();
    }
}
