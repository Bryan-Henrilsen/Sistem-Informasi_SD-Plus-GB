<?php

namespace App\Domains\LesEkskul\Services;

use App\Models\Kelas;
use App\Models\Siswa;
use App\Models\SiswaKelas;
use App\Models\TahunAjaran;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

// Akun orang tua dan klaim anak (FR-1, FR-1b; Ringkasan v1.11 #36)
class AkunOrangTuaService
{
    // Kelas tahun ajaran aktif beserta siswanya, untuk dipilih saat klaim (kelas dulu, lalu nama)
    public function kelasUntukKlaim(): Collection
    {
        $tahunAjaran = TahunAjaran::aktif()->first();

        return Kelas::query()
            ->where('tahun_ajaran_id', $tahunAjaran?->id)
            ->with(['siswaKelas' => fn ($q) => $q->whereHas('siswa', fn ($s) => $s->where('status_sekolah', 'aktif'))->with('siswa:id,nama')])
            ->orderBy('jenjang')
            ->orderBy('nama_kelas')
            ->get();
    }

    // Klaim langsung aktif tanpa verifikasi; satu anak boleh diklaim ayah & ibu (FR-1b)
    public function klaim(User $orangTua, Siswa $siswa): void
    {
        DB::transaction(function () use ($orangTua, $siswa) {
            $masalah = match (true) {
                ! $orangTua->hasRole('orang_tua') => 'Hanya akun orang tua yang dapat mengklaim anak.',
                $siswa->kelasTahunAktif()->doesntExist() => "{$siswa->nama} tidak terdaftar di kelas tahun ajaran ini.",
                $orangTua->anak()->whereKey($siswa->id)->exists() => "{$siswa->nama} sudah ada di daftar anak Anda.",
                default => null,
            };

            if ($masalah) {
                throw ValidationException::withMessages(['siswa_id' => $masalah]);
            }

            $orangTua->anak()->attach($siswa->id);
        });
    }

    // Orang tua melepas klaimnya sendiri, atau TU menghapus klaim yang salah (FR-1b)
    public function lepas(User $orangTua, Siswa $siswa): void
    {
        $orangTua->anak()->detach($siswa->id);
    }

    // TU membuat password sementara bagi orang tua yang lupa password dan tidak punya email (#36).
    // Password dikembalikan sekali untuk disampaikan ke orang tua, tidak disimpan dalam bentuk asli
    public function resetPassword(User $orangTua): string
    {
        if (! $orangTua->hasRole('orang_tua')) {
            throw ValidationException::withMessages(['akun' => 'Reset password di sini hanya untuk akun orang tua.']);
        }

        $password = Str::lower(Str::random(4)).random_int(1000, 9999);
        $orangTua->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();

        return $password;
    }

    // TU menambah siswa yang belum ada di data kelas (mis. murid pindahan) agar bisa diklaim (#36)
    public function tambahSiswa(string $nama, Kelas $kelas, ?string $jenisKelamin): Siswa
    {
        $nama = Str::squish($nama);

        return DB::transaction(function () use ($nama, $kelas, $jenisKelamin) {
            $sudahAda = SiswaKelas::where('kelas_id', $kelas->id)
                ->whereHas('siswa', fn ($q) => $q->whereRaw('LOWER(nama) = ?', [Str::lower($nama)]))
                ->exists();

            if ($nama === '' || $sudahAda) {
                throw ValidationException::withMessages(['nama' => $nama === '' ? 'Nama wajib diisi.' : "{$nama} sudah ada di kelas {$kelas->nama_lengkap}."]);
            }

            $siswa = Siswa::create(['nama' => $nama, 'jenis_kelamin' => $jenisKelamin]);
            SiswaKelas::create(['siswa_id' => $siswa->id, 'kelas_id' => $kelas->id]);

            return $siswa;
        });
    }
}
