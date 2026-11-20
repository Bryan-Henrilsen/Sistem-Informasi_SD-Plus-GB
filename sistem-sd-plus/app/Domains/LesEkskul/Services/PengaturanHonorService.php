<?php

namespace App\Domains\LesEkskul\Services;

use App\Domains\LesEkskul\Enums\JenisPengajar;
use App\Domains\LesEkskul\Enums\PeranHonor;
use App\Domains\LesEkskul\Enums\TipeKegiatan;
use App\Domains\LesEkskul\Models\HonorFlatJenis;
use App\Domains\LesEkskul\Models\HonorFlatPengaturan;
use App\Domains\LesEkskul\Models\PenerimaPeranHonor;
use App\Domains\LesEkskul\Models\SkemaHonor;
use App\Models\TahunAjaran;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

// Pengaturan honor oleh Kepala Sekolah: skema persen (FR-17), penerima peran pengelola (FR-18), honor flat (FR-25b).
// Setiap perubahan menjadi baris baru "berlaku sejak hari ini" (perubahan di hari yang sama menimpa baris hari itu),
// sehingga honor periode yang sudah dihitung tidak ikut berubah
class PengaturanHonorService
{
    // Peran pengelola yang menerima porsi persen; Pengajar, Pendamping, Kas, Yayasan tidak diatur di sini
    public const PERAN_PENGELOLA = [PeranHonor::PenanggungJawab, PeranHonor::Bendahara, PeranHonor::AdminTu, PeranHonor::AdminIt];

    // Domain email penanda penerima tanpa akun login (mis. petugas kebersihan, #33)
    public const DOMAIN_TANPA_AKUN = 'tanpa-akun.sdplus.local';

    public const KOLOM_PERSEN = [
        'persen_pengajar', 'persen_guru_pendamping', 'persen_kas_sekolah', 'persen_yayasan',
        'persen_penanggung_jawab', 'persen_bendahara', 'persen_admin_tu', 'persen_admin_it',
    ];

    // Tiga skema yang dipakai: ekskul internal, ekskul eksternal, les tambahan
    public function skemaBerlaku(): Collection
    {
        return collect([
            [TipeKegiatan::Ekstrakurikuler, JenisPengajar::Internal],
            [TipeKegiatan::Ekstrakurikuler, JenisPengajar::Eksternal],
            [TipeKegiatan::LesTambahan, null],
        ])->map(fn (array $k) => [
            'tipe' => $k[0],
            'jenis' => $k[1],
            'skema' => SkemaHonor::berlakuPada($k[0], $k[1], today()),
            'riwayat' => SkemaHonor::where('tipe_kegiatan', $k[0])->where('jenis_pengajar', $k[1])->count(),
        ]);
    }

    public function simpanSkema(TipeKegiatan $tipe, ?JenisPengajar $jenis, array $persen): SkemaHonor
    {
        // Kolom yang tidak berlaku untuk skema ini dikosongkan: pendamping hanya ekskul, yayasan hanya les
        $nilai = collect(self::KOLOM_PERSEN)->mapWithKeys(fn (string $kolom) => [$kolom => match (true) {
            $kolom === 'persen_yayasan' && $tipe !== TipeKegiatan::LesTambahan,
            $kolom === 'persen_guru_pendamping' && $tipe === TipeKegiatan::LesTambahan => null,
            default => round((float) ($persen[$kolom] ?? 0), 2),
        }]);

        if ($nilai->contains(fn ($p) => $p !== null && ($p < 0 || $p > 100))) {
            throw $this->gagal('persen', 'Setiap persen harus di antara 0 dan 100.');
        }

        $total = round($nilai->sum(), 2);
        if (abs($total - 100) > 0.001) {
            throw $this->gagal('persen', "Total persen harus 100%, sekarang {$total}%.");
        }

        return SkemaHonor::updateOrCreate(
            ['tipe_kegiatan' => $tipe, 'jenis_pengajar' => $jenis, 'berlaku_sejak' => today()->toDateString()],
            $nilai->all(),
        );
    }

    // [peran => user_id]; hanya peran yang berubah yang menambah baris baru
    public function simpanPenerimaPeran(array $penerima, User $oleh): void
    {
        DB::transaction(function () use ($penerima, $oleh) {
            foreach (self::PERAN_PENGELOLA as $peran) {
                $userId = (int) ($penerima[$peran->value] ?? 0) ?: null;

                if ($userId === null || PenerimaPeranHonor::pemegang($peran, today())?->id === $userId) {
                    continue;
                }

                PenerimaPeranHonor::updateOrCreate(
                    ['peran' => $peran, 'berlaku_sejak' => today()->toDateString()],
                    ['user_id' => $userId, 'diatur_oleh' => $oleh->id],
                );
            }
        });
    }

    // Honor flat per jenis untuk tahun ajaran aktif: nominal per orang dan penerima beserta jumlahnya (FR-25b)
    public function simpanHonorFlat(HonorFlatJenis $jenis, int $nominal, array $penerima, User $oleh): HonorFlatPengaturan
    {
        if ($nominal < 0) {
            throw $this->gagal('nominal', 'Nominal tidak boleh negatif.');
        }

        $tahunAjaran = TahunAjaran::aktif()->firstOrFail();
        $daftar = collect($penerima)
            ->mapWithKeys(fn ($jumlah, $userId) => [(int) $userId => ['jumlah' => max(1, (int) $jumlah)]])
            ->all();

        return DB::transaction(function () use ($jenis, $nominal, $daftar, $oleh, $tahunAjaran) {
            $pengaturan = HonorFlatPengaturan::updateOrCreate(
                ['honor_flat_jenis_id' => $jenis->id, 'berlaku_sejak' => today()->toDateString()],
                ['tahun_ajaran_id' => $tahunAjaran->id, 'nominal_per_guru' => $nominal, 'diatur_oleh' => $oleh->id],
            );
            $pengaturan->penerima()->sync($daftar);

            return $pengaturan;
        });
    }

    // Pengaturan honor flat yang berlaku pada tanggal tertentu untuk tahun ajaran itu
    public function honorFlatBerlaku(HonorFlatJenis $jenis, TahunAjaran $tahunAjaran, $tanggal = null): ?HonorFlatPengaturan
    {
        return HonorFlatPengaturan::where('honor_flat_jenis_id', $jenis->id)
            ->where('tahun_ajaran_id', $tahunAjaran->id)
            ->where('berlaku_sejak', '<=', ($tanggal ?? today())->toDateString())
            ->latest('berlaku_sejak')
            ->with('penerima')
            ->first();
    }

    // Guru, staf, dan penerima tanpa akun: calon penerima peran pengelola & honor flat
    public function orangPenerima(): array
    {
        return User::query()
            ->where(fn ($q) => $q->whereHas('roles', fn ($r) => $r->whereIn('name', ['guru', 'tu', 'bendahara', 'kepala_sekolah', 'waka_kurikulum']))
                ->orWhere('email', 'like', '%@'.self::DOMAIN_TANPA_AKUN))
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->map(fn (User $u) => [
                'id' => $u->id,
                'nama' => $u->name,
                'tanpa_akun' => str_ends_with((string) $u->email, '@'.self::DOMAIN_TANPA_AKUN),
            ])
            ->all();
    }

    // Penerima honor flat yang tidak punya akun (mis. petugas kebersihan): baris users tanpa role, tidak bisa membuka menu apa pun
    public function tambahPenerimaTanpaAkun(string $nama): User
    {
        $nama = trim($nama);

        if ($nama === '') {
            throw $this->gagal('nama', 'Nama wajib diisi.');
        }

        return User::create([
            'name' => $nama,
            'email' => 'penerima-'.Str::lower(Str::random(12)).'@'.self::DOMAIN_TANPA_AKUN,
            'password' => Str::random(40),
        ]);
    }

    // Peran pengelola yang belum punya penerima; membuat honor belum bisa dihitung (#33)
    public function masalahPeran($tanggal = null): array
    {
        return collect(self::PERAN_PENGELOLA)
            ->reject(fn (PeranHonor $peran) => PenerimaPeranHonor::pemegang($peran, $tanggal ?? today()) !== null)
            ->map(fn (PeranHonor $peran) => 'Penerima porsi '.self::labelPeran($peran).' belum diatur.')
            ->values()
            ->all();
    }

    public static function labelPeran(PeranHonor $peran): string
    {
        return match ($peran) {
            PeranHonor::Pengajar => 'pengajar',
            PeranHonor::GuruPendamping => 'guru pendamping',
            PeranHonor::KasSekolah => 'kas sekolah',
            PeranHonor::Yayasan => 'yayasan',
            PeranHonor::PenanggungJawab => 'penanggung jawab',
            PeranHonor::Bendahara => 'bendahara',
            PeranHonor::AdminTu => 'admin TU',
            PeranHonor::AdminIt => 'admin IT',
            PeranHonor::HonorFlat => 'honor flat',
        };
    }

    private function gagal(string $field, string $pesan): ValidationException
    {
        return ValidationException::withMessages([$field => $pesan]);
    }
}
