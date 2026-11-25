<?php

namespace App\Domains\LesEkskul\Services;

use App\Domains\LesEkskul\Models\LesTambahan;
use App\Domains\LesEkskul\Models\Pendaftaran;
use App\Domains\LesEkskul\Models\RiwayatWaliKelas;
use App\Models\Guru;
use App\Models\Kelas;
use App\Models\TahunAjaran;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

// Data master Kelas & Les Tambahan per tahun ajaran (Ringkasan v1.11 #43). Les tambahan selalu satu per kelas
// dan pengajarnya wali kelas, jadi keduanya diurus bersama
class KelasLesService
{
    // Kelas satu tahun ajaran urut jenjang lalu nama, dengan wali, jumlah siswa, dan les tambahannya
    public function daftar(TahunAjaran $tahunAjaran): Collection
    {
        return Kelas::where('tahun_ajaran_id', $tahunAjaran->id)
            ->with(['waliKelas.user', 'lesTambahan'])
            ->withCount('siswaKelas')
            ->orderBy('jenjang')
            ->orderBy('nama_kelas')
            ->get()
            ->map(function (Kelas $k) {
                $peserta = $k->lesTambahan ? Pendaftaran::aktif()->where('les_tambahan_id', $k->lesTambahan->id)->count() : 0;
                $pernahDaftar = $k->lesTambahan && $k->lesTambahan->pendaftaran()->exists();

                return [
                    'id' => $k->id,
                    'jenjang' => $k->jenjang,
                    'nama_kelas' => $k->nama_kelas,
                    'nama_pelindung' => $k->nama_pelindung,
                    'nama_lengkap' => $k->nama_lengkap,
                    'wali_kelas_id' => $k->wali_kelas_id,
                    'wali_kelas' => $k->waliKelas?->user?->name,
                    'jumlah_siswa' => $k->siswa_kelas_count,
                    'les' => $k->lesTambahan ? ['biaya' => $k->lesTambahan->biaya, 'peserta' => $peserta, 'bisa_dihapus' => ! $pernahDaftar] : null,
                    'jenjang_terkunci' => $k->siswa_kelas_count > 0,
                    'bisa_dihapus' => $this->bisaDihapus($k),
                    'riwayat_wali' => $this->riwayatWali($k),
                ];
            });
    }

    // Guru aktif untuk pilihan wali kelas, beserta kelas yang sudah dipegangnya di tahun ajaran ini
    public function guruPilihan(TahunAjaran $tahunAjaran): Collection
    {
        $wali = Kelas::where('tahun_ajaran_id', $tahunAjaran->id)->whereNotNull('wali_kelas_id')->get()->keyBy('wali_kelas_id');

        return Guru::where('status_aktif', true)
            ->with('user:id,name')
            ->get()
            ->sortBy(fn (Guru $g) => $g->user->name)
            ->map(fn (Guru $g) => ['id' => $g->id, 'nama' => $g->user->name, 'wali_di' => $wali->get($g->id)?->nama_kelas])
            ->values();
    }

    // Tambah/ubah kelas beserta les tambahannya. $data: jenjang, nama_kelas, nama_pelindung, wali_kelas_id, ada_les, biaya_les
    public function simpan(TahunAjaran $tahunAjaran, array $data, ?Kelas $kelas = null): Kelas
    {
        $isian = [
            'jenjang' => (int) $data['jenjang'],
            'nama_kelas' => Str::upper(Str::squish($data['nama_kelas'])),
            'nama_pelindung' => filled($data['nama_pelindung'] ?? null) ? Str::squish($data['nama_pelindung']) : null,
            'wali_kelas_id' => filled($data['wali_kelas_id'] ?? null) ? (int) $data['wali_kelas_id'] : null,
        ];
        $adaLes = (bool) ($data['ada_les'] ?? false);

        return DB::transaction(function () use ($tahunAjaran, $isian, $adaLes, $data, $kelas) {
            $this->periksa($tahunAjaran, $isian, $adaLes, $kelas);

            $kelas ??= new Kelas(['tahun_ajaran_id' => $tahunAjaran->id]);
            $kelas->fill($isian);
            // Mengubah wali lewat form = membetulkan salah input: wali yang sedang berjalan di riwayat ikut dibetulkan (#50)
            if ($kelas->exists && $kelas->isDirty('wali_kelas_id') && $isian['wali_kelas_id']) {
                RiwayatWaliKelas::where('kelas_id', $kelas->id)->whereNull('tanggal_selesai')->update(['guru_id' => $isian['wali_kelas_id']]);
            }
            $kelas->save();

            $les = $kelas->lesTambahan()->first();
            if ($adaLes) {
                $les ? $les->update(['biaya' => (int) $data['biaya_les']]) : LesTambahan::create(['kelas_id' => $kelas->id, 'biaya' => (int) $data['biaya_les']]);
            } elseif ($les) {
                $les->delete(); // sudah dipastikan belum punya pendaftar di periksa()
            }

            return $kelas;
        });
    }

    // Pergantian wali kelas di tengah tahun ajaran (#50): honor les bulan pergantian dibagi per hari sekolah.
    // Riwayat pertama kali dibuat lengkap (wali lama sejak awal s.d. tanggal terakhir, wali baru mulai tanggalnya)
    public function gantiWali(Kelas $kelas, int $guruBaruId, string $tanggalTerakhir, string $tanggalMulai, User $oleh): void
    {
        DB::transaction(function () use ($kelas, $guruBaruId, $tanggalTerakhir, $tanggalMulai, $oleh) {
            $kelas = Kelas::whereKey($kelas->id)->lockForUpdate()->firstOrFail();
            $terakhir = CarbonImmutable::parse($tanggalTerakhir);
            $mulai = CarbonImmutable::parse($tanggalMulai);
            $berjalan = RiwayatWaliKelas::where('kelas_id', $kelas->id)->whereNull('tanggal_selesai')->first();
            $guruLamaId = $berjalan?->guru_id ?? $kelas->wali_kelas_id;

            $salah = [];
            if (! $guruLamaId) {
                $salah['guru_id'] = "Kelas {$kelas->nama_lengkap} belum punya wali kelas; isi lewat Ubah kelas.";
            } elseif ($guruBaruId === $guruLamaId) {
                $salah['guru_id'] = 'Wali kelas baru harus berbeda dengan wali kelas sekarang.';
            }
            if ($berjalan?->tanggal_mulai && $terakhir->lt($berjalan->tanggal_mulai)) {
                $salah['tanggal_terakhir'] = 'Tanggal terakhir sebelum wali kelas sekarang mulai bertugas ('.$berjalan->tanggal_mulai->locale('id')->translatedFormat('j F Y').').';
            }
            if ($mulai->lte($terakhir)) {
                $salah['tanggal_mulai'] = 'Tanggal mulai wali baru harus sesudah tanggal terakhir wali lama.';
            }
            // Guru aktif & belum menjadi wali kelas lain di tahun ajaran ini (aturan yang sama dengan form kelas)
            if (! isset($salah['guru_id']) && $masalah = $this->masalahWali($kelas->tahunAjaran, $guruBaruId, $kelas)) {
                $salah['guru_id'] = $masalah;
            }
            if ($salah !== []) {
                throw ValidationException::withMessages($salah);
            }

            if ($berjalan) {
                $berjalan->update(['tanggal_selesai' => $terakhir]);
            } else {
                RiwayatWaliKelas::create(['kelas_id' => $kelas->id, 'guru_id' => $guruLamaId, 'tanggal_selesai' => $terakhir, 'dicatat_oleh' => $oleh->id]);
            }
            RiwayatWaliKelas::create(['kelas_id' => $kelas->id, 'guru_id' => $guruBaruId, 'tanggal_mulai' => $mulai, 'dicatat_oleh' => $oleh->id]);
            $kelas->update(['wali_kelas_id' => $guruBaruId]);
        });
    }

    // Riwayat wali kelas (terlama dulu), kosong bila belum pernah berganti
    public function riwayatWali(Kelas $kelas): Collection
    {
        return RiwayatWaliKelas::where('kelas_id', $kelas->id)
            ->with('guru.user:id,name')
            ->orderByRaw('tanggal_mulai NULLS FIRST')
            ->orderBy('id')
            ->get()
            ->map(fn (RiwayatWaliKelas $r) => [
                'id' => $r->id,
                'nama' => $r->guru->user->name,
                'mulai' => $r->tanggal_mulai?->toDateString(),
                'selesai' => $r->tanggal_selesai?->toDateString(),
            ]);
    }

    // Hapus permanen (agar nama kelas bisa dipakai lagi) hanya bila kelas belum dipakai di mana pun
    public function hapus(Kelas $kelas): void
    {
        DB::transaction(function () use ($kelas) {
            if (! $this->bisaDihapus($kelas)) {
                throw ValidationException::withMessages([
                    'kelas' => "Kelas {$kelas->nama_lengkap} sudah dipakai (siswa, pendaftar les, atau jadwal guru), jadi tidak bisa dihapus.",
                ]);
            }

            $kelas->lesTambahan()->delete();
            $kelas->forceDelete();
        });
    }

    // Samakan biaya les semua kelas satu jenjang yang sudah punya les; hasilnya jumlah kelas yang diubah
    public function samakanBiaya(TahunAjaran $tahunAjaran, int $jenjang, int $biaya): int
    {
        return LesTambahan::where('tahun_ajaran_id', $tahunAjaran->id)
            ->whereHas('kelas', fn ($q) => $q->where('jenjang', $jenjang))
            ->update(['biaya' => $biaya, 'updated_at' => now()]);
    }

    // Tahun ajaran sebelumnya yang kelasnya bisa disalin ke $tujuan; null bila $tujuan sudah punya kelas
    public function sumberSalinan(TahunAjaran $tujuan): ?TahunAjaran
    {
        if (Kelas::where('tahun_ajaran_id', $tujuan->id)->exists()) {
            return null;
        }

        return TahunAjaran::where('tanggal_mulai', '<', $tujuan->tanggal_mulai->toDateString())
            ->whereIn('id', Kelas::select('tahun_ajaran_id'))
            ->orderByDesc('tanggal_mulai')
            ->first();
    }

    // Salin struktur kelas & biaya les; wali kelas dikosongkan dan siswa tidak ikut (#43). Hasilnya jumlah kelas
    public function salinDariTahunLalu(TahunAjaran $tujuan): int
    {
        return DB::transaction(function () use ($tujuan) {
            TahunAjaran::whereKey($tujuan->id)->lockForUpdate()->first();
            $sumber = $this->sumberSalinan($tujuan)
                ?? throw ValidationException::withMessages(['salin' => 'Tidak ada kelas yang bisa disalin, atau tahun ajaran ini sudah punya kelas.']);

            $kelasLama = Kelas::where('tahun_ajaran_id', $sumber->id)->with('lesTambahan')->get();

            foreach ($kelasLama as $lama) {
                $baru = Kelas::create([...$lama->only(['jenjang', 'nama_kelas', 'nama_pelindung']), 'tahun_ajaran_id' => $tujuan->id]);

                if ($lama->lesTambahan) {
                    LesTambahan::create(['kelas_id' => $baru->id, 'biaya' => $lama->lesTambahan->biaya]);
                }
            }

            return $kelasLama->count();
        });
    }

    // Satu guru aktif hanya menjadi wali satu kelas per tahun ajaran; null = boleh
    private function masalahWali(TahunAjaran $tahunAjaran, int $guruId, ?Kelas $kelas): ?string
    {
        $guru = Guru::with('user:id,name')->find($guruId);
        $kelasLain = Kelas::where('tahun_ajaran_id', $tahunAjaran->id)
            ->where('wali_kelas_id', $guruId)
            ->when($kelas, fn ($q) => $q->whereKeyNot($kelas->id))
            ->first();

        return match (true) {
            ! $guru?->status_aktif => 'Pilih guru yang masih aktif.',
            $kelasLain !== null => "{$guru->user->name} sudah menjadi wali kelas {$kelasLain->nama_lengkap}.",
            default => null,
        };
    }

    private function periksa(TahunAjaran $tahunAjaran, array $isian, bool $adaLes, ?Kelas $kelas): void
    {
        $salah = [];

        // Termasuk kelas terhapus: indeks unik (tahun_ajaran_id, nama_kelas) tetap berlaku untuk baris soft delete
        $namaDipakai = Kelas::withTrashed()->where('tahun_ajaran_id', $tahunAjaran->id)
            ->whereRaw('upper(nama_kelas) = ?', [$isian['nama_kelas']])
            ->when($kelas, fn ($q) => $q->whereKeyNot($kelas->id))
            ->exists();
        if ($namaDipakai) {
            $salah['nama_kelas'] = "Kelas {$isian['nama_kelas']} sudah ada di tahun ajaran ini.";
        }

        if ($isian['wali_kelas_id'] && $masalah = $this->masalahWali($tahunAjaran, $isian['wali_kelas_id'], $kelas)) {
            $salah['wali_kelas_id'] = $masalah;
        }

        if ($kelas && $kelas->jenjang !== $isian['jenjang'] && $kelas->siswaKelas()->exists()) {
            $salah['jenjang'] = 'Jenjang tidak bisa diubah karena kelas sudah punya siswa.';
        }

        $les = $kelas?->lesTambahan()->first();
        if (! $adaLes && $les && $les->pendaftaran()->exists()) {
            $salah['ada_les'] = 'Les tambahan kelas ini sudah punya pendaftar, jadi tidak bisa dihapus.';
        }

        if ($salah !== []) {
            throw ValidationException::withMessages($salah);
        }
    }

    private function bisaDihapus(Kelas $kelas): bool
    {
        $les = $kelas->lesTambahan()->first();

        return ! $kelas->siswaKelas()->exists()
            && ! ($les && $les->pendaftaran()->exists())
            && ! DB::table('kisi_kisi_tugas')->where('kelas_id', $kelas->id)->exists()
            && ! DB::table('jadwal_mengajar')->where('kelas_id', $kelas->id)->exists();
    }
}
