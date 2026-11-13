<?php

namespace App\Domains\LesEkskul\Services;

use App\Domains\LesEkskul\Enums\StatusPendaftaran;
use App\Domains\LesEkskul\Models\Ekstrakurikuler;
use App\Domains\LesEkskul\Models\JadwalEkskul;
use App\Domains\LesEkskul\Models\LesTambahan;
use App\Domains\LesEkskul\Models\Pendaftaran;
use App\Enums\StatusSekolah;
use App\Models\Siswa;
use App\Models\SiswaKelas;
use App\Models\TahunAjaran;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Pendaftaran les tambahan & ekstrakurikuler (FR-2 s.d. FR-6, FR-26, FR-27, FR-33; Ringkasan v1.11 #28).
// Aturan ditulis sekali di sini supaya input manual TU dan form orang tua (nanti) memakai aturan yang sama.
class PendaftaranService
{
    private const HARI = [1 => 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];

    public function __construct(private TagihanService $tagihan) {}

    // Kegiatan yang ditawarkan untuk siswa di tahun ajaran aktif: les kelasnya + ekskul induk yang sesuai jenjang.
    // Ekskul wajib otomatis tidak didaftarkan (FR-24); kelas paralel diisi lewat assign, bukan dipilih langsung (FR-26)
    public function pilihanKegiatan(Siswa $siswa): Collection
    {
        $kelas = $siswa->kelasTahunAktif?->kelas;

        if (! $kelas) {
            return collect();
        }

        $les = LesTambahan::where('kelas_id', $kelas->id)->with('kelas')->get();
        $ekskul = Ekstrakurikuler::where('tahun_ajaran_id', $kelas->tahun_ajaran_id)
            ->kegiatanInduk()
            ->where('wajib_otomatis', false)
            ->untukJenjang($kelas->jenjang)
            ->with('jadwal', 'kelasParalel')
            ->orderBy('nama')
            ->get();

        return $les->concat($ekskul);
    }

    // error = tidak boleh disimpan sama sekali; peringatan = boleh disimpan setelah TU mengonfirmasi (hanya kuota penuh, #28)
    public function periksa(Siswa $siswa, LesTambahan|Ekstrakurikuler $kegiatan): array
    {
        return [
            'error' => $this->cariMasalah($siswa, $kegiatan),
            'peringatan' => $kegiatan instanceof Ekstrakurikuler ? $this->peringatanKuota($kegiatan) : null,
        ];
    }

    // Input manual TU (FR-6); tagihan langsung dibuat dari bulan daftar sampai periode laporan terbaru (FR-9, #19)
    public function daftar(Siswa $siswa, LesTambahan|Ekstrakurikuler $kegiatan, string $tanggalDaftar, bool $abaikanKuota = false): Pendaftaran
    {
        return DB::transaction(function () use ($siswa, $kegiatan, $tanggalDaftar, $abaikanKuota) {
            // Kunci baris siswa (klik ganda tidak membuat pendaftaran dobel) dan kegiatan (pendaftar bersamaan antre untuk kuota)
            Siswa::whereKey($siswa->id)->lockForUpdate()->first();
            $kegiatan::whereKey($kegiatan->id)->lockForUpdate()->first();

            $tahunAjaran = $kegiatan->tahunAjaran;
            if (! Carbon::parse($tanggalDaftar)->between($tahunAjaran->tanggal_mulai, $tahunAjaran->tanggal_selesai)) {
                throw ValidationException::withMessages(['tanggal_daftar' => "Tanggal daftar harus di dalam tahun ajaran {$tahunAjaran->nama}."]);
            }

            $this->tolakBilaBermasalah($siswa, $kegiatan, $abaikanKuota);

            $pendaftaran = Pendaftaran::create([
                'siswa_id' => $siswa->id,
                'les_tambahan_id' => $kegiatan instanceof LesTambahan ? $kegiatan->id : null,
                'ekstrakurikuler_id' => $kegiatan instanceof Ekstrakurikuler ? $kegiatan->id : null,
                'tanggal_daftar' => $tanggalDaftar,
            ]);

            $this->tagihan->buatSetelahDaftar($pendaftaran);

            return $pendaftaran;
        });
    }

    // FR-27: pindahkan pendaftaran dari kegiatan induk ke salah satu kelas paralelnya (atau antar kelas paralel).
    // Tagihan tidak berubah karena biaya kelas paralel selalu sama dengan induknya (A6 #8)
    public function assignKelasParalel(Pendaftaran $pendaftaran, Ekstrakurikuler $tujuan, bool $abaikanKuota = false): void
    {
        DB::transaction(function () use ($pendaftaran, $tujuan, $abaikanKuota) {
            Ekstrakurikuler::whereKey($tujuan->id)->lockForUpdate()->first();

            $sekarang = $pendaftaran->ekstrakurikuler;
            $indukId = $sekarang?->parent_ekstrakurikuler_id ?? $sekarang?->id;
            $jenjang = $sekarang ? $pendaftaran->jenjang() : null;

            $masalah = match (true) {
                $pendaftaran->status !== StatusPendaftaran::Aktif || ! $sekarang => 'Hanya pendaftaran ekskul yang masih aktif yang dapat di-assign.',
                $tujuan->parent_ekstrakurikuler_id != $indukId => "{$tujuan->nama} bukan kelas paralel dari kegiatan ini.",
                $tujuan->id == $sekarang->id => "Siswa sudah berada di {$tujuan->nama}.",
                default => $jenjang && ($bentrok = $this->cariBentrok($pendaftaran->siswa, $tujuan, $jenjang, $pendaftaran))
                    ? "Jadwal {$tujuan->nama} bentrok dengan {$bentrok}."
                    : null,
            };

            if ($masalah) {
                throw ValidationException::withMessages(['kelas_paralel' => $masalah]);
            }

            if (! $abaikanKuota && $peringatan = $this->peringatanKuota($tujuan)) {
                throw ValidationException::withMessages(['kuota' => $peringatan.' Centang konfirmasi untuk tetap meng-assign.']);
            }

            $pendaftaran->update(['ekstrakurikuler_id' => $tujuan->id]);
        });
    }

    // Laporan TU (FR-33): siswa tahun ajaran aktif yang belum memenuhi kewajiban mendaftar; kosong bila sakelar dimatikan
    public function siswaBelumMemenuhiKewajiban(TahunAjaran $tahunAjaran): Collection
    {
        $wajibEkskul = (bool) config('les_ekskul.wajib_minimal_satu_ekskul');
        $wajibLes = (bool) config('les_ekskul.wajib_les_tambahan');

        if (! $wajibEkskul && ! $wajibLes) {
            return collect();
        }

        $semesterGenap = $tahunAjaran->semesterPada()?->urutan === 2;

        return SiswaKelas::where('tahun_ajaran_id', $tahunAjaran->id)
            ->whereHas('siswa', fn (Builder $q) => $q->where('status_sekolah', StatusSekolah::Aktif))
            ->with(['kelas', 'siswa.pendaftaran' => fn ($q) => $q->aktif()->with('ekstrakurikuler', 'lesTambahan')])
            ->get()
            ->map(function (SiswaKelas $sk) use ($tahunAjaran, $wajibEkskul, $wajibLes, $semesterGenap) {
                $aktif = $sk->siswa->pendaftaran;
                $kurang = [];

                // Olimpiade dan kegiatan induk berkelas paralel dihitung; kegiatan wajib otomatis tidak (FR-33)
                $punyaEkskul = $aktif->contains(fn (Pendaftaran $p) => $p->ekstrakurikuler
                    && $p->ekstrakurikuler->tahun_ajaran_id == $tahunAjaran->id
                    && ! $p->ekstrakurikuler->wajib_otomatis);
                $bebasEkskul = $semesterGenap && $sk->kelas->jenjang == 6; // kelas 6 semester genap tidak wajib (#23)

                if ($wajibEkskul && ! $punyaEkskul && ! $bebasEkskul) {
                    $kurang[] = 'ekstrakurikuler';
                }

                if ($wajibLes && ! $aktif->contains(fn (Pendaftaran $p) => $p->lesTambahan?->tahun_ajaran_id == $tahunAjaran->id)) {
                    $kurang[] = 'les tambahan';
                }

                return ['siswa' => $sk->siswa, 'kelas' => $sk->kelas, 'kurang' => $kurang];
            })
            ->filter(fn (array $baris) => $baris['kurang'] !== [])
            ->sortBy(fn (array $baris) => sprintf('%d%s %s', $baris['kelas']->jenjang, $baris['kelas']->nama_kelas, $baris['siswa']->nama))
            ->values();
    }

    // Jumlah pendaftar aktif; kegiatan induk dihitung gabungan dengan seluruh kelas paralelnya (FR-4)
    public function terisi(Ekstrakurikuler $ekskul): int
    {
        $ids = $ekskul->adalahKelasParalel() ? [$ekskul->id] : $this->idSeluruhKelas($ekskul);

        return Pendaftaran::aktif()->whereIn('ekstrakurikuler_id', $ids)->count();
    }

    // Jadwal yang berlaku untuk siswa jenjang tertentu (mis. Basket beda jam per jenjang);
    // kelas paralel tanpa jadwal sendiri memakai jadwal induknya
    public function jadwalUntuk(Ekstrakurikuler $ekskul, int $jenjang): Collection
    {
        $jadwal = $ekskul->jadwal->isEmpty() && $ekskul->induk ? $ekskul->induk->jadwal : $ekskul->jadwal;

        return $jadwal
            ->filter(fn (JadwalEkskul $j) => $this->sesuaiJenjang($j->jenjang_min, $j->jenjang_max, $jenjang))
            ->sortBy(['hari', 'jam_mulai'])
            ->values();
    }

    // "Jumat 14:00–15:00"
    public function teksJadwal(JadwalEkskul $jadwal): string
    {
        return self::HARI[$jadwal->hari].' '.substr($jadwal->jam_mulai, 0, 5).'–'.substr($jadwal->jam_selesai, 0, 5);
    }

    private function tolakBilaBermasalah(Siswa $siswa, LesTambahan|Ekstrakurikuler $kegiatan, bool $abaikanKuota): void
    {
        $hasil = $this->periksa($siswa, $kegiatan);

        if ($hasil['error']) {
            throw ValidationException::withMessages(['kegiatan' => $hasil['error']]);
        }

        if ($hasil['peringatan'] && ! $abaikanKuota) {
            throw ValidationException::withMessages(['kuota' => $hasil['peringatan'].' Centang konfirmasi untuk tetap mendaftarkan.']);
        }
    }

    private function cariMasalah(Siswa $siswa, LesTambahan|Ekstrakurikuler $kegiatan): ?string
    {
        $kelas = $siswa->kelasTahunAktif?->kelas;

        if (! $kelas) {
            return 'Siswa belum punya kelas di tahun ajaran aktif.';
        }

        if ($kegiatan->tahun_ajaran_id != $kelas->tahun_ajaran_id) {
            return 'Kegiatan ini bukan milik tahun ajaran aktif.';
        }

        if ($kegiatan instanceof LesTambahan && $kegiatan->kelas_id != $kelas->id) {
            return "Les tambahan hanya untuk kelas siswa sendiri ({$kelas->nama_lengkap}).";
        }

        if ($kegiatan instanceof Ekstrakurikuler) {
            if ($kegiatan->wajib_otomatis) {
                return "{$kegiatan->nama} diikuti otomatis sesuai jenjang, tidak perlu didaftarkan.";
            }

            if ($kegiatan->adalahKelasParalel()) {
                return 'Daftarkan ke kegiatan induknya, lalu assign kelas paralel.';
            }

            if (! $this->sesuaiJenjang($kegiatan->jenjang_min, $kegiatan->jenjang_max, $kelas->jenjang)) {
                return "{$kegiatan->nama} hanya untuk kelas {$kegiatan->jenjang_min}–{$kegiatan->jenjang_max}.";
            }
        }

        if ($this->sudahTerdaftar($siswa, $kegiatan)) {
            return 'Siswa sudah terdaftar aktif di kegiatan ini.';
        }

        // Bentrok jadwal selalu ditolak: anak tidak mungkin ikut dua kegiatan di jam yang sama (FR-5, #28)
        if ($kegiatan instanceof Ekstrakurikuler && $bentrok = $this->cariBentrok($siswa, $kegiatan, $kelas->jenjang)) {
            return "Jadwal bentrok dengan {$bentrok}.";
        }

        return null;
    }

    // Duplikasi = sudah aktif di kegiatan yang sama, termasuk di kelas paralel induk yang sama (FR-3); daftar ulang setelah berhenti boleh
    private function sudahTerdaftar(Siswa $siswa, LesTambahan|Ekstrakurikuler $kegiatan): bool
    {
        $aktif = $siswa->pendaftaran()->aktif();

        return $kegiatan instanceof LesTambahan
            ? $aktif->where('les_tambahan_id', $kegiatan->id)->exists()
            : $aktif->whereIn('ekstrakurikuler_id', $this->idSeluruhKelas($kegiatan))->exists();
    }

    // Nama ekskul aktif lain siswa yang jadwalnya beririsan (hari sama, jam saling tumpang tindih), beserta jadwalnya
    private function cariBentrok(Siswa $siswa, Ekstrakurikuler $ekskul, int $jenjang, ?Pendaftaran $kecuali = null): ?string
    {
        $jadwalBaru = $this->jadwalUntuk($ekskul, $jenjang);

        if ($jadwalBaru->isEmpty()) {
            return null;
        }

        $ekskulLain = $siswa->pendaftaran()->aktif()
            ->whereHas('ekstrakurikuler', fn (Builder $q) => $q->where('tahun_ajaran_id', $ekskul->tahun_ajaran_id))
            ->when($kecuali, fn (Builder $q) => $q->whereKeyNot($kecuali->id))
            ->with('ekstrakurikuler.jadwal', 'ekstrakurikuler.induk.jadwal')
            ->get();

        foreach ($ekskulLain as $pendaftaran) {
            foreach ($this->jadwalUntuk($pendaftaran->ekstrakurikuler, $jenjang) as $lama) {
                foreach ($jadwalBaru as $baru) {
                    // Jam disimpan "HH:MM:SS", jadi perbandingan teks sama dengan perbandingan waktu
                    if ($lama->hari == $baru->hari && $lama->jam_mulai < $baru->jam_selesai && $baru->jam_mulai < $lama->jam_selesai) {
                        return "{$pendaftaran->ekstrakurikuler->nama} ({$this->teksJadwal($lama)})";
                    }
                }
            }
        }

        return null;
    }

    private function peringatanKuota(Ekstrakurikuler $ekskul): ?string
    {
        if ($ekskul->kuota === null) {
            return null; // tanpa batas kuota
        }

        $terisi = $this->terisi($ekskul);

        return $terisi >= $ekskul->kuota ? "Kuota {$ekskul->nama} sudah penuh ({$terisi}/{$ekskul->kuota})." : null;
    }

    // id kegiatan induk beserta seluruh kelas paralelnya
    private function idSeluruhKelas(Ekstrakurikuler $induk): array
    {
        return $induk->kelasParalel()->pluck('id')->push($induk->id)->all();
    }

    // Jenjang sasaran kosong = semua jenjang
    private function sesuaiJenjang(?int $min, ?int $max, int $jenjang): bool
    {
        return $min === null || ($jenjang >= $min && $jenjang <= $max);
    }
}
