<?php

namespace App\Domains\LesEkskul\Services;

use App\Domains\LesEkskul\Models\PengajarEkskul;
use App\Models\Guru;
use App\Models\Kelas;
use App\Models\MataPelajaran;
use App\Models\TahunAjaran;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

// Data master Akun Guru & Staf oleh Kepala Sekolah (Ringkasan v1.11 #46). Akun tidak dihapus karena dirujuk banyak data;
// gantinya dinonaktifkan (users.aktif) dan status guru mengikuti status akun
class AkunStafService
{
    public const PERAN = ['kepala_sekolah', 'tu', 'bendahara', 'waka_kurikulum', 'guru'];

    public function daftar(): Collection
    {
        $tahunAktif = TahunAjaran::aktif()->value('id');
        $wali = Kelas::where('tahun_ajaran_id', $tahunAktif)->whereNotNull('wali_kelas_id')->get()->keyBy('wali_kelas_id');

        return User::role(self::PERAN)
            ->with(['roles:id,name', 'guru.mataPelajaran:id,kode'])
            ->orderByDesc('aktif')
            ->orderBy('name')
            ->get()
            ->map(fn (User $u) => [
                'id' => $u->id,
                'nama' => $u->name,
                'email' => $u->email,
                'peran' => $u->roles->pluck('name')->intersect(self::PERAN)->values(),
                'kode_guru' => $u->guru?->kode_guru,
                'nuptk' => $u->guru?->nuptk,
                'mapel_ids' => $u->guru?->mataPelajaran->pluck('id')->values() ?? [], // mapel yang diampu (#47)
                'mapel' => $u->guru?->mataPelajaran->pluck('kode')->values() ?? [],
                'wali_kelas' => $u->guru ? $wali->get($u->guru->id)?->nama_lengkap : null,
                'aktif' => $u->aktif,
            ]);
    }

    // Tambah akun: hasilnya [user, password awal] — password ditampilkan sekali kepada Kepala Sekolah
    public function tambah(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $peran = $this->periksa($data);
            $password = $this->passwordAcak();

            $user = User::create(['name' => Str::squish($data['nama']), 'email' => Str::lower(trim($data['email'])), 'password' => $password]);
            $user->syncRoles($peran);
            $this->simpanGuru($user, $data, $peran);

            return [$user, $password];
        });
    }

    public function ubah(User $user, array $data, User $oleh): User
    {
        return DB::transaction(function () use ($user, $data, $oleh) {
            $this->pastikanStaf($user);
            $peran = $this->periksa($data, $user);

            if ($user->is($oleh) && ! in_array('kepala_sekolah', $peran, true)) {
                throw ValidationException::withMessages(['peran' => 'Anda tidak dapat mencabut peran Kepala Sekolah dari akun Anda sendiri.']);
            }
            if ($user->hasRole('kepala_sekolah') && ! in_array('kepala_sekolah', $peran, true)) {
                $this->pastikanMasihAdaKepsek($user);
            }

            $user->update(['name' => Str::squish($data['nama']), 'email' => Str::lower(trim($data['email']))]);
            // Peran non-staf (mis. orang tua yang juga guru) dipertahankan
            $user->syncRoles([...$user->roles->pluck('name')->diff(self::PERAN)->all(), ...$peran]);
            $this->simpanGuru($user, $data, $peran);

            return $user;
        });
    }

    public function resetPassword(User $user): string
    {
        $this->pastikanStaf($user);
        $password = $this->passwordAcak();
        $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();

        return $password;
    }

    // Nonaktifkan: tidak bisa login, sesi diputus, guru ikut nonaktif. Hasilnya peringatan (wali kelas/pengajar yang perlu diganti)
    public function nonaktifkan(User $user, User $oleh): array
    {
        return DB::transaction(function () use ($user, $oleh) {
            $this->pastikanStaf($user);

            if ($user->is($oleh)) {
                throw ValidationException::withMessages(['akun' => 'Anda tidak dapat menonaktifkan akun Anda sendiri.']);
            }
            if ($user->hasRole('kepala_sekolah')) {
                $this->pastikanMasihAdaKepsek($user);
            }

            $user->forceFill(['aktif' => false, 'remember_token' => Str::random(60)])->save();
            $user->guru?->update(['status_aktif' => false]);
            DB::table('sessions')->where('user_id', $user->id)->delete(); // sesi yang sedang terbuka langsung berakhir

            return $this->peringatan($user);
        });
    }

    public function aktifkan(User $user): void
    {
        $this->pastikanStaf($user);
        $user->update(['aktif' => true]);
        $user->guru?->update(['status_aktif' => true]);
    }

    // Wali kelas tahun ajaran aktif & pengajar ekskul aktif yang perlu diganti setelah akun dinonaktifkan
    public function peringatan(User $user): array
    {
        $pesan = [];
        $kelas = $user->guru ? Kelas::where('tahun_ajaran_id', TahunAjaran::aktif()->value('id'))->where('wali_kelas_id', $user->guru->id)->first() : null;

        if ($kelas) {
            $pesan[] = "Masih wali kelas {$kelas->nama_lengkap}; ganti walinya di halaman Kelas & Les.";
        }
        if (PengajarEkskul::where('user_id', $user->id)->where('status_aktif', true)->whereHas('ekstrakurikuler')->exists()) {
            $pesan[] = 'Masih tercatat sebagai pengajar ekskul; atur penggantinya di halaman Pengajar Ekskul.';
        }

        return $pesan;
    }

    // Aturan yang butuh data lain; hasilnya daftar peran staf yang valid
    private function periksa(array $data, ?User $user = null): array
    {
        $peran = array_values(array_intersect(self::PERAN, $data['peran'] ?? []));
        $salah = [];

        if ($peran === []) {
            $salah['peran'] = 'Pilih minimal satu peran.';
        }

        $emailDipakai = User::whereRaw('LOWER(email) = ?', [Str::lower(trim($data['email']))])->when($user, fn ($q) => $q->whereKeyNot($user->id))->exists();
        if ($emailDipakai) {
            $salah['email'] = 'Email ini sudah dipakai akun lain.';
        }

        if (in_array('guru', $peran, true)) {
            $guruId = $user?->guru?->id;
            if (blank($data['kode_guru'] ?? null)) {
                $salah['kode_guru'] = 'Kode guru wajib diisi untuk peran Guru.';
            } elseif (Guru::withTrashed()->whereRaw('UPPER(kode_guru) = ?', [Str::upper(trim($data['kode_guru']))])->when($guruId, fn ($q) => $q->whereKeyNot($guruId))->exists()) {
                $salah['kode_guru'] = 'Kode guru ini sudah dipakai.';
            }
            if (filled($data['nuptk'] ?? null) && Guru::withTrashed()->where('nuptk', trim($data['nuptk']))->when($guruId, fn ($q) => $q->whereKeyNot($guruId))->exists()) {
                $salah['nuptk'] = 'NUPTK ini sudah dipakai.';
            }
        }

        if ($salah !== []) {
            throw ValidationException::withMessages($salah);
        }

        return $peran;
    }

    // Baris guru dibuat/diubah bila berperan Guru; bila peran Guru dicabut, data guru tetap disimpan (dirujuk jadwal/wali/anak guru)
    private function simpanGuru(User $user, array $data, array $peran): void
    {
        if (! in_array('guru', $peran, true)) {
            return;
        }

        $guru = $user->guru()->updateOrCreate([], [
            'kode_guru' => Str::upper(trim($data['kode_guru'])),
            'nuptk' => filled($data['nuptk'] ?? null) ? trim($data['nuptk']) : null,
            'status_aktif' => $user->aktif,
        ]);

        // Mapel yang diampu (#47): mapel baru harus aktif; mapel nonaktif yang sudah tercatat boleh tetap dipertahankan
        if (array_key_exists('mapel_ids', $data)) {
            $dipilih = collect($data['mapel_ids'] ?? [])->map(fn ($id) => (int) $id)->unique();
            $sudahAda = $guru->mataPelajaran()->pluck('mata_pelajaran.id');
            $sah = MataPelajaran::whereKey($dipilih)->where(fn ($q) => $q->where('aktif', true)->orWhereIn('id', $sudahAda))->pluck('id');
            $guru->mataPelajaran()->sync($sah);
        }
    }

    private function pastikanStaf(User $user): void
    {
        abort_unless($user->hasAnyRole(self::PERAN), 404); // orang tua & penerima tanpa akun bukan urusan halaman ini
    }

    private function pastikanMasihAdaKepsek(User $kecuali): void
    {
        $lain = User::role('kepala_sekolah')->where('aktif', true)->whereKeyNot($kecuali->id)->exists();

        if (! $lain) {
            throw ValidationException::withMessages(['peran' => 'Harus ada minimal satu Kepala Sekolah aktif.']);
        }
    }

    // 10 karakter huruf kecil + angka, cukup untuk aturan password production (minimal 8 huruf & angka)
    private function passwordAcak(): string
    {
        return Str::lower(Str::random(6)).random_int(1000, 9999);
    }
}
