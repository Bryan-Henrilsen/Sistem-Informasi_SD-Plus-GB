<?php

namespace App\Domains\LesEkskul\Services;

use App\Domains\LesEkskul\Models\Ekstrakurikuler;
use App\Domains\LesEkskul\Models\HonorKegiatan;
use App\Domains\LesEkskul\Models\JadwalEkskul;
use App\Domains\LesEkskul\Models\Pendaftaran;
use App\Models\TahunAjaran;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

// Data master Kegiatan Ekstrakurikuler: kegiatan, jadwal, kelas paralel, dan salin dari tahun ajaran lalu (Ringkasan v1.11 #42).
// Pengajar, pendamping, kategori, dan cara hitung honor diatur di PengajarEkskulService (#33)
class EkstrakurikulerService
{
    public const JENIS_BIASA = 'biasa';

    public const JENIS_HONOR_FLAT = 'honor_flat'; // mis. Olimpiade: biaya 0, tetap berkuota (FR-25)

    public const JENIS_WAJIB = 'wajib'; // Bina Karakter/Pramuka: biaya 0, tanpa kuota, tanpa pendaftaran (FR-24)

    public function __construct(private PendaftaranService $pendaftaran) {}

    public static function jenis(Ekstrakurikuler $e): string
    {
        return match (true) {
            $e->wajib_otomatis => self::JENIS_WAJIB,
            $e->honor_flat => self::JENIS_HONOR_FLAT,
            default => self::JENIS_BIASA,
        };
    }

    // Kegiatan induk satu tahun ajaran beserta jadwal, kelas paralel, dan jumlah peserta aktif, urut nama
    public function daftar(TahunAjaran $tahunAjaran): Collection
    {
        return Ekstrakurikuler::where('tahun_ajaran_id', $tahunAjaran->id)
            ->kegiatanInduk()
            ->with(['jadwal' => fn ($q) => $q->orderBy('hari')->orderBy('jam_mulai'), 'kelasParalel' => fn ($q) => $q->orderBy('nama')])
            ->orderBy('nama')
            ->get()
            ->map(fn (Ekstrakurikuler $e) => [
                'id' => $e->id,
                'nama' => $e->nama,
                'jenis' => self::jenis($e),
                'biaya' => $e->biaya,
                'biaya_non_honor' => $e->biaya_non_honor,
                'kuota' => $e->kuota,
                'jenjang_min' => $e->jenjang_min,
                'jenjang_max' => $e->jenjang_max,
                'terisi' => $this->pendaftaran->terisi($e),
                'jadwal' => $e->jadwal->map(fn (JadwalEkskul $j) => [
                    'id' => $j->id,
                    'hari' => $j->hari,
                    'jam_mulai' => substr($j->jam_mulai, 0, 5),
                    'jam_selesai' => substr($j->jam_selesai, 0, 5),
                    'jenjang_min' => $j->jenjang_min,
                    'jenjang_max' => $j->jenjang_max,
                    'lokasi' => $j->lokasi,
                ])->values(),
                'kelas_paralel' => $e->kelasParalel->map(fn (Ekstrakurikuler $k) => [
                    'id' => $k->id,
                    'nama' => $k->nama,
                    'kuota' => $k->kuota,
                    'terisi' => $this->pendaftaran->terisi($k),
                    'bisa_dihapus' => ! $this->punyaPendaftar([$k->id]),
                ])->values(),
                // Pendaftar yang sudah berhenti/pindah tetap dihitung: riwayat & tagihannya merujuk kegiatan ini
                'bisa_dihapus' => ! $this->punyaPendaftar($this->idSeluruhKelas($e)),
            ]);
    }

    // Tambah atau ubah satu kegiatan induk beserta jadwal dan kelas paralelnya; semua atau tidak sama sekali.
    // $data sudah lolos validasi format di request: jenis, nama, biaya, biaya_non_honor, kuota, jenjang_min/max,
    // jadwal[] {hari, jam_mulai, jam_selesai, jenjang_min, jenjang_max, lokasi}, kelas_paralel[] {id?, nama, kuota}
    public function simpan(TahunAjaran $tahunAjaran, array $data, ?Ekstrakurikuler $kegiatan = null): Ekstrakurikuler
    {
        $isian = $this->isianKegiatan($data);
        $jadwal = collect($data['jadwal'] ?? []);
        $paralel = $isian['wajib_otomatis'] ? collect() : collect($data['kelas_paralel'] ?? []);

        $this->periksa($tahunAjaran, $isian, $jadwal, $paralel, $kegiatan);

        return DB::transaction(function () use ($tahunAjaran, $isian, $jadwal, $paralel, $kegiatan) {
            $kegiatan ??= new Ekstrakurikuler(['tahun_ajaran_id' => $tahunAjaran->id]);
            $kegiatan->fill($isian)->save();

            $kegiatan->jadwal()->delete(); // jadwal diganti seluruhnya sesuai isian form
            foreach ($jadwal as $j) {
                $kegiatan->jadwal()->create([
                    'hari' => (int) $j['hari'],
                    'jam_mulai' => $j['jam_mulai'],
                    'jam_selesai' => $j['jam_selesai'],
                    'jenjang_min' => $j['jenjang_min'] ?? null,
                    'jenjang_max' => $j['jenjang_max'] ?? null,
                    'lokasi' => trim($j['lokasi']),
                ]);
            }

            $this->simpanKelasParalel($kegiatan, $paralel);

            return $kegiatan;
        });
    }

    // Hapus kegiatan (beserta kelas paralel & jadwal) hanya bila belum pernah punya pendaftar
    public function hapus(Ekstrakurikuler $kegiatan): void
    {
        DB::transaction(function () use ($kegiatan) {
            $ids = $kegiatan->adalahKelasParalel() ? [$kegiatan->id] : $this->idSeluruhKelas($kegiatan);

            if ($this->punyaPendaftar($ids) || HonorKegiatan::whereIn('ekstrakurikuler_id', $ids)->exists()) {
                throw ValidationException::withMessages([
                    'kegiatan' => "{$kegiatan->nama} sudah punya pendaftar, jadi tidak bisa dihapus. Riwayat pendaftaran dan tagihannya merujuk kegiatan ini.",
                ]);
            }

            // Kelas paralel dulu (FK ke induk), jadwal/pengajar/pendamping ikut terhapus lewat cascade
            Ekstrakurikuler::whereIn('id', $ids)->where('id', '!=', $kegiatan->id)->delete();
            $kegiatan->delete();
        });
    }

    // Tahun ajaran sebelumnya yang bisa disalin ke $tujuan; null bila $tujuan sudah punya kegiatan atau tidak ada sumber
    public function sumberSalinan(TahunAjaran $tujuan): ?TahunAjaran
    {
        if (Ekstrakurikuler::where('tahun_ajaran_id', $tujuan->id)->exists()) {
            return null;
        }

        return TahunAjaran::where('tanggal_mulai', '<', $tujuan->tanggal_mulai->toDateString())
            ->whereIn('id', Ekstrakurikuler::select('tahun_ajaran_id'))
            ->orderByDesc('tanggal_mulai')
            ->first();
    }

    // Salin semua kegiatan tahun ajaran sebelumnya beserta jadwal, kelas paralel, pengajar, pendamping, kategori,
    // dan cara hitung honor; pendaftaran & tagihan tidak ikut. Hasilnya jumlah kegiatan induk yang disalin
    public function salinDariTahunLalu(TahunAjaran $tujuan): int
    {
        return DB::transaction(function () use ($tujuan) {
            TahunAjaran::whereKey($tujuan->id)->lockForUpdate()->first(); // dua klik bersamaan tidak menyalin dua kali
            $sumber = $this->sumberSalinan($tujuan)
                ?? throw ValidationException::withMessages(['salin' => 'Tidak ada kegiatan yang bisa disalin, atau tahun ajaran ini sudah punya kegiatan.']);

            $induk = Ekstrakurikuler::where('tahun_ajaran_id', $sumber->id)
                ->kegiatanInduk()
                ->with('jadwal', 'pengajar', 'pendamping', 'kelasParalel.pengajar', 'kelasParalel.pendamping')
                ->get();

            foreach ($induk as $lama) {
                $baru = $this->salinSatu($lama, $tujuan);

                foreach ($lama->jadwal as $j) {
                    $baru->jadwal()->create($j->only(['hari', 'jam_mulai', 'jam_selesai', 'jenjang_min', 'jenjang_max', 'lokasi']));
                }

                foreach ($lama->kelasParalel as $kelas) {
                    $this->salinSatu($kelas, $tujuan, $baru);
                }
            }

            return $induk->count();
        });
    }

    private function salinSatu(Ekstrakurikuler $lama, TahunAjaran $tujuan, ?Ekstrakurikuler $induk = null): Ekstrakurikuler
    {
        $baru = Ekstrakurikuler::create([
            ...$lama->only(['nama', 'biaya', 'biaya_non_honor', 'kuota', 'kategori_pendamping', 'wajib_otomatis', 'jenjang_min', 'jenjang_max', 'honor_flat', 'honor_per_kelas_paralel']),
            'tahun_ajaran_id' => $tujuan->id,
            'parent_ekstrakurikuler_id' => $induk?->id,
        ]);
        $baru->pengajar()->sync($lama->pengajar->pluck('id'));
        $baru->pendamping()->sync($lama->pendamping->pluck('id'));

        return $baru;
    }

    // Kolom kegiatan sesuai jenisnya: honor flat & wajib otomatis selalu Rp0; wajib otomatis tanpa kuota
    private function isianKegiatan(array $data): array
    {
        $jenis = $data['jenis'];
        $berbayar = $jenis === self::JENIS_BIASA;

        return [
            'nama' => Str::squish($data['nama']),
            'biaya' => $berbayar ? (int) $data['biaya'] : 0,
            'biaya_non_honor' => $berbayar ? (int) ($data['biaya_non_honor'] ?? 0) : 0,
            'kuota' => $jenis === self::JENIS_WAJIB || ! filled($data['kuota'] ?? null) ? null : (int) $data['kuota'],
            'jenjang_min' => filled($data['jenjang_min'] ?? null) ? (int) $data['jenjang_min'] : null,
            'jenjang_max' => filled($data['jenjang_max'] ?? null) ? (int) $data['jenjang_max'] : null,
            'wajib_otomatis' => $jenis === self::JENIS_WAJIB,
            'honor_flat' => $jenis === self::JENIS_HONOR_FLAT,
        ];
    }

    // Aturan yang butuh data lain (nama ganda, pendaftar, jenjang jadwal); pesan memakai kunci field form
    private function periksa(TahunAjaran $tahunAjaran, array $isian, Collection $jadwal, Collection $paralel, ?Ekstrakurikuler $kegiatan): void
    {
        $salah = [];
        [$min, $max] = [$isian['jenjang_min'], $isian['jenjang_max']];

        if (($min === null) !== ($max === null)) {
            $salah['jenjang_min'] = 'Isi jenjang dari dan sampai, atau kosongkan keduanya untuk semua jenjang.';
        } elseif ($min !== null && $min > $max) {
            $salah['jenjang_min'] = 'Jenjang "dari" tidak boleh lebih besar dari "sampai".';
        } elseif ($isian['wajib_otomatis'] && $min === null) {
            $salah['jenjang_min'] = 'Kegiatan wajib otomatis harus punya jenjang peserta, mis. kelas 1–3.';
        }

        if ($isian['biaya_non_honor'] > $isian['biaya']) {
            $salah['biaya_non_honor'] = 'Uang non-honor tidak boleh melebihi biaya.';
        }

        // Nama tidak boleh sama dengan kegiatan/kelas paralel lain di tahun ajaran yang sama
        $milikSendiri = $kegiatan ? $this->idSeluruhKelas($kegiatan) : [];
        $namaLain = Ekstrakurikuler::where('tahun_ajaran_id', $tahunAjaran->id)->whereNotIn('id', $milikSendiri)->pluck('nama')->map(fn ($n) => Str::lower($n));
        $namaForm = $paralel->pluck('nama')->map(fn ($n) => Str::lower(Str::squish($n)))->push(Str::lower($isian['nama']));

        if ($namaLain->contains(Str::lower($isian['nama']))) {
            $salah['nama'] = "Sudah ada kegiatan bernama {$isian['nama']} di tahun ajaran ini.";
        }
        $namaParalel = $paralel->pluck('nama')->map(fn ($n) => Str::lower(Str::squish($n)));
        if ($namaForm->duplicates()->isNotEmpty() || $namaLain->intersect($namaParalel)->isNotEmpty()) {
            $salah['kelas_paralel'] ??= 'Nama kelas paralel harus berbeda satu sama lain dan dari kegiatan lain.';
        }

        foreach ($jadwal as $i => $j) {
            $jMin = filled($j['jenjang_min'] ?? null) ? (int) $j['jenjang_min'] : null;
            $jMax = filled($j['jenjang_max'] ?? null) ? (int) $j['jenjang_max'] : null;

            if ($j['jam_mulai'] >= $j['jam_selesai']) {
                $salah["jadwal.{$i}.jam_selesai"] = 'Jam selesai harus setelah jam mulai.';
            }
            if (($jMin === null) !== ($jMax === null) || ($jMin !== null && $jMin > $jMax)) {
                $salah["jadwal.{$i}.jenjang_min"] = 'Isi jenjang jadwal lengkap (dari ≤ sampai), atau kosongkan keduanya.';
            } elseif ($jMin !== null && $min !== null && ($jMin < $min || $jMax > $max)) {
                $salah["jadwal.{$i}.jenjang_min"] = "Jenjang jadwal harus di dalam jenjang kegiatan (kelas {$min}–{$max}).";
            }
        }

        if ($kegiatan) {
            $adaPendaftar = $this->punyaPendaftar($this->idSeluruhKelas($kegiatan));

            if ($adaPendaftar && self::jenis($kegiatan) !== $this->jenisDariIsian($isian)) {
                $salah['jenis'] = 'Jenis kegiatan tidak bisa diubah karena sudah ada pendaftar.';
            }

            // Kelas paralel yang dihapus dari form harus belum punya pendaftar
            $dipertahankan = $paralel->pluck('id')->filter()->map(fn ($id) => (int) $id);
            $dibuang = $kegiatan->kelasParalel()->whereNotIn('id', $dipertahankan)->get();
            foreach ($dibuang as $k) {
                if ($this->punyaPendaftar([$k->id])) {
                    $salah['kelas_paralel'] = "Kelas paralel {$k->nama} sudah punya pendaftar, jadi tidak bisa dihapus.";
                }
            }

            // id kelas paralel kiriman harus benar-benar milik kegiatan ini
            if ($dipertahankan->diff($kegiatan->kelasParalel()->pluck('id'))->isNotEmpty()) {
                $salah['kelas_paralel'] = 'Data kelas paralel tidak cocok. Muat ulang halaman lalu coba lagi.';
            }
        } elseif ($paralel->pluck('id')->filter()->isNotEmpty()) {
            $salah['kelas_paralel'] = 'Data kelas paralel tidak cocok. Muat ulang halaman lalu coba lagi.';
        }

        if ($salah !== []) {
            throw ValidationException::withMessages($salah);
        }
    }

    // Kelas paralel: ubah yang ada, buat yang baru, hapus yang dibuang; biaya selalu mengikuti induk (#42)
    private function simpanKelasParalel(Ekstrakurikuler $induk, Collection $paralel): void
    {
        $dipertahankan = $paralel->pluck('id')->filter()->map(fn ($id) => (int) $id)->all();
        $induk->kelasParalel()->whereNotIn('id', $dipertahankan)->delete();

        foreach ($paralel as $k) {
            $isian = [
                'nama' => Str::squish($k['nama']),
                'kuota' => filled($k['kuota'] ?? null) ? (int) $k['kuota'] : null,
                'biaya' => $induk->biaya,
                'biaya_non_honor' => $induk->biaya_non_honor,
                'honor_flat' => $induk->honor_flat,
            ];

            if (! empty($k['id'])) {
                $induk->kelasParalel()->whereKey((int) $k['id'])->first()?->update($isian);
            } else {
                $induk->kelasParalel()->create([...$isian, 'tahun_ajaran_id' => $induk->tahun_ajaran_id]);
            }
        }
    }

    private function jenisDariIsian(array $isian): string
    {
        return $isian['wajib_otomatis'] ? self::JENIS_WAJIB : ($isian['honor_flat'] ? self::JENIS_HONOR_FLAT : self::JENIS_BIASA);
    }

    // Pendaftar dengan status apa pun (aktif, berhenti, pindah)
    private function punyaPendaftar(array $ids): bool
    {
        return Pendaftaran::whereIn('ekstrakurikuler_id', $ids)->exists();
    }

    private function idSeluruhKelas(Ekstrakurikuler $induk): array
    {
        return $induk->kelasParalel()->pluck('id')->push($induk->id)->all();
    }
}
