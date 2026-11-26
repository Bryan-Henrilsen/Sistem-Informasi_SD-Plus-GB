<?php

namespace App\Domains\LesEkskul\Services;

use App\Enums\StatusSekolah;
use App\Models\Kelas;
use App\Models\Siswa;
use App\Models\SiswaKelas;
use App\Models\TahunAjaran;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

// Impor data siswa dua tahap (FR-28, Ringkasan v1.11 #53).
// Juli: template aplikasi (nama, kelas, L/P) untuk semua kelas tahun ajaran tujuan — siswa lama dicocokkan lewat nama + jenjang
// tahun lalu, siswa yang sudah punya kelas di tahun itu tapi beda kelas di file dipindah. Oktober: ekspor Dapodik untuk tahun
// ajaran aktif — dicocokkan lewat NISN lalu nama + kelas, melengkapi NIS/NISN/tanggal lahir/jenis kelamin.
// Nama mirip & kembar tidak pernah dicocokkan otomatis: TU memilih siswanya dan nama yang dipakai (aplikasi atau file).
// Pratinjau selalu dihitung ulang dari data terbaru; simpan memakai DataSiswaService sehingga aturan data siswa tetap berlaku
class ImporSiswaService
{
    public const JULI = 'juli';

    public const DAPODIK = 'dapodik';

    private ?array $nomorTerpakai = null;

    public function __construct(private DataSiswaService $dataSiswa) {}

    // Hasil: ['baris' => [...], 'ringkasan' => [status => jumlah], 'tidak_di_file' => [...]]
    public function pratinjau(string $tahap, TahunAjaran $tahunAjaran, array $baris): array
    {
        $kelas = Kelas::where('tahun_ajaran_id', $tahunAjaran->id)->get();
        $siswaTahunIni = $this->siswaDiTahun($tahunAjaran, true);
        $hasil = $tahap === self::JULI
            ? $this->pratinjauJuli($tahunAjaran, $baris, $kelas, $siswaTahunIni)
            : $this->pratinjauDapodik($baris, $kelas, $siswaTahunIni);

        $disebut = collect($hasil)->flatMap(fn (array $b) => array_filter([$b['siswa']['id'] ?? null, ...array_column($b['kandidat'], 'id')]))->unique();

        return [
            'baris' => $hasil,
            'ringkasan' => collect($hasil)->countBy('status')->all(),
            // Siswa tahun ini yang tidak disebut di file: hanya informasi, tidak diubah
            'tidak_di_file' => $siswaTahunIni->reject(fn (array $s) => $disebut->contains($s['id']))
                ->sortBy(fn (array $s) => $s['kelas'].'|'.$s['nama'])
                ->map(fn (array $s) => ['id' => $s['id'], 'nama' => $s['nama'], 'kelas' => $s['kelas']])->values()->all(),
        ];
    }

    // Terapkan keputusan TU: [no baris => ['aksi' => cocok|baru|lewati, 'siswa_id' => ?, 'nama' => aplikasi|file]].
    // Baris tanpa keputusan memakai bawaan pratinjau; baris "pilih" wajib diputuskan. Semua atau tidak sama sekali
    public function simpan(string $tahap, TahunAjaran $tahunAjaran, array $baris, array $keputusan): array
    {
        return DB::transaction(function () use ($tahap, $tahunAjaran, $baris, $keputusan) {
            $jumlah = ['baru' => 0, 'dicocokkan' => 0, 'dipindah' => 0, 'dilewati' => 0];

            foreach ($this->pratinjau($tahap, $tahunAjaran, $baris)['baris'] as $b) {
                $k = $b['status'] === 'error' ? $b['bawaan'] : (($keputusan[$b['no']] ?? null) ?: $b['bawaan']);
                $aksi = $k['aksi'] ?? null;

                if ($aksi === null) {
                    throw $this->gagal("Baris {$b['no']} ({$b['nama']}): pilih siswa yang cocok, buat baru, atau lewati.");
                }

                if ($aksi === 'lewati') {
                    $jumlah['dilewati']++;

                    continue;
                }

                if ($aksi === 'baru') {
                    $this->pastikanNomorBebas($b, null);
                    $this->dataSiswa->simpan($tahunAjaran, [
                        'nama' => $b['nama'], 'jenis_kelamin' => $b['jk'], 'kelas_id' => $b['kelas_id'],
                        'nis' => $b['nis'] ?? null, 'nisn' => $b['nisn'] ?? null, 'tanggal_lahir' => $b['tanggal_lahir'] ?? null,
                    ]);
                    $jumlah['baru']++;

                    continue;
                }

                // Cocokkan: hanya dengan siswa yang ditawarkan pratinjau
                $pilihan = collect([$b['siswa'], ...$b['kandidat']])->filter()->firstWhere('id', (int) ($k['siswa_id'] ?? 0))
                    ?? throw $this->gagal("Baris {$b['no']} ({$b['nama']}): siswa yang dipilih tidak ada di daftar kecocokan.");
                $pakaiNamaFile = ($k['nama'] ?? 'aplikasi') === 'file';
                $jumlah['dicocokkan']++;

                // Tidak ada yang berubah: tidak perlu disimpan ulang (hemat waktu di database online)
                if ($b['status'] === 'tetap' && ! ($pakaiNamaFile && $b['nama_beda']) && ($tahap === self::DAPODIK || $pilihan['jk'] !== null || $b['jk'] === null)) {
                    continue;
                }

                $siswa = Siswa::lockForUpdate()->findOrFail($pilihan['id']);
                $sekarang = $siswa->riwayatKelas()->where('tahun_ajaran_id', $tahunAjaran->id)->first();
                // Juli: kelas mengikuti file; Dapodik: kelas tetap seperti di aplikasi (hanya siswa baru Dapodik yang ikut Rombel)
                $kelasId = $tahap === self::JULI ? $b['kelas_id'] : ($sekarang?->kelas_id ?? $b['kelas_id']);
                if ($sekarang && $sekarang->kelas_id !== $kelasId) {
                    $jumlah['dipindah']++;
                }

                $dapodik = $tahap === self::DAPODIK;
                if ($dapodik) {
                    $this->pastikanNomorBebas($b, $siswa->id);
                }
                $this->dataSiswa->simpan($tahunAjaran, [
                    'nama' => $pakaiNamaFile ? $b['nama'] : $siswa->nama,
                    // Juli hanya mengisi L/P yang masih kosong; Dapodik menjadi acuan data resmi (kolom kosong tidak menghapus)
                    'jenis_kelamin' => $dapodik ? ($b['jk'] ?? $siswa->jenis_kelamin) : ($siswa->jenis_kelamin ?? $b['jk']),
                    'nis' => $dapodik ? ($b['nis'] ?? $siswa->nis) : $siswa->nis,
                    'nisn' => $dapodik ? ($b['nisn'] ?? $siswa->nisn) : $siswa->nisn,
                    'tanggal_lahir' => $dapodik ? ($b['tanggal_lahir'] ?? $siswa->tanggal_lahir?->toDateString()) : $siswa->tanggal_lahir?->toDateString(),
                    'anak_guru_id' => $siswa->anak_guru_id,
                    'kelas_id' => $kelasId,
                ], $siswa);
            }

            return $jumlah;
        });
    }

    private function pratinjauJuli(TahunAjaran $tahunAjaran, array $baris, Collection $kelas, Collection $siswaTahunIni): array
    {
        $tahunLalu = TahunAjaran::where('tanggal_mulai', '<', $tahunAjaran->tanggal_mulai->toDateString())->orderByDesc('tanggal_mulai')->first();
        $sudahPunyaKelas = $siswaTahunIni->pluck('id');
        // Siswa aktif tahun lalu yang belum punya kelas di tahun tujuan
        $siswaTahunLalu = $tahunLalu
            ? $this->siswaDiTahun($tahunLalu, false)->reject(fn (array $s) => $sudahPunyaKelas->contains($s['id']))->values()
            : collect();
        $kembarDiFile = collect($baris)->countBy(fn (array $r) => $this->kunci($r['nama']).'|'.Str::upper($r['kelas']));
        $terpakai = [];

        return collect($baris)->map(function (array $r) use ($kelas, $siswaTahunIni, $siswaTahunLalu, $kembarDiFile, &$terpakai) {
            $b = $this->barisDasar($r);
            if ($r['nama'] === '') {
                return $this->error($b, 'Nama kosong.');
            }
            $k = $this->cariKelas($kelas, $r['kelas']) ?? null;
            if (! $k) {
                return $this->error($b, "Kelas \"{$r['kelas']}\" tidak ada di tahun ajaran ini.");
            }
            $b['kelas_id'] = $k->id;
            $b['kelas'] = $k->nama_lengkap;
            if ($kembarDiFile[$this->kunci($r['nama']).'|'.Str::upper($r['kelas'])] > 1) {
                return $this->error($b, 'Nama ini muncul lebih dari sekali di kelas yang sama pada file.');
            }

            $bebas = fn (Collection $c) => $c->reject(fn (array $s) => isset($terpakai[$s['id']]))->values();
            // Kandidat utama: sudah di tahun tujuan jenjang sama, atau tahun lalu jenjang sebelumnya (naik) / sama (tinggal kelas)
            $utama = $bebas($siswaTahunIni->where('jenjang', $k->jenjang)
                ->concat($siswaTahunLalu->filter(fn (array $s) => in_array($s['jenjang'], [$k->jenjang - 1, $k->jenjang], true))));
            // Kandidat cadangan: sudah di tahun tujuan tetapi jenjang lain (mis. dinaikkan massal padahal tinggal kelas)
            $cadangan = $bebas($siswaTahunIni->where('jenjang', '!=', $k->jenjang));
            [$persis, $mirip] = $this->cocokkan($r['nama'], $utama);

            if ($persis->count() === 1) {
                $s = $persis->first();
                $terpakai[$s['id']] = true;
                $b = $this->cocok($b, $s);

                if (! $s['tahun_ini']) {
                    return [...$b, 'status' => 'lama', 'pesan' => $s['jenjang'] === $k->jenjang ? "Tinggal kelas (tahun lalu {$s['kelas']})." : "Tahun lalu {$s['kelas']}."];
                }

                return $s['kelas_id'] === $k->id
                    ? [...$b, 'status' => 'tetap']
                    : [...$b, 'status' => 'pindah', 'pesan' => "Pindah {$s['kelas']} → {$k->nama_lengkap}."];
            }

            [$persisLain, $miripLain] = $this->cocokkan($r['nama'], $cadangan);

            return $this->pilihAtauBaru($b, $persis->concat($mirip)->concat($persisLain)->concat($miripLain));
        })->all();
    }

    private function pratinjauDapodik(array $baris, Collection $kelas, Collection $siswaTahunIni): array
    {
        $nisnDiFile = collect($baris)->pluck('nisn')->filter()->countBy();
        $terpakai = [];

        return collect($baris)->map(function (array $r) use ($kelas, $siswaTahunIni, $nisnDiFile, &$terpakai) {
            $b = $this->barisDasar($r) + ['nis' => $r['nis'], 'nisn' => $r['nisn'], 'tanggal_lahir' => $r['tanggal_lahir']];
            $k = $this->cariKelas($kelas, $r['kelas']);
            if (! $k) {
                return $this->error($b, "Rombel \"{$r['kelas']}\" tidak ada di tahun ajaran aktif.");
            }
            $b['kelas_id'] = $k->id;
            $b['kelas'] = $k->nama_lengkap;
            if ($r['nisn'] && $nisnDiFile[$r['nisn']] > 1) {
                return $this->error($b, "NISN {$r['nisn']} muncul lebih dari sekali di file.");
            }

            $kumpulan = $siswaTahunIni->reject(fn (array $s) => isset($terpakai[$s['id']]))->values();
            // Otomatis hanya bila pasti: NISN yang sama, atau satu-satunya nama persis di kelas Rombel
            $s = $r['nisn'] ? $kumpulan->firstWhere('nisn', $r['nisn']) : null;
            if (! $s) {
                $persisDiKelas = $this->cocokkan($r['nama'], $kumpulan->where('kelas_id', $k->id))[0];
                $s = $persisDiKelas->count() === 1 ? $persisDiKelas->first() : null;
            }

            if ($s) {
                $terpakai[$s['id']] = true;
                $b = $this->cocok($b, $s);
                $b['perubahan'] = $this->perubahan($s, $r);
                if ($bentrok = $this->bentrokNomor($r, $s['id'])) {
                    return $this->error($b, $bentrok);
                }
                if ($s['kelas_id'] !== $k->id) {
                    $b['pesan'] = "Di aplikasi tercatat di {$s['kelas']}; kelas tidak diubah.";
                }

                return [...$b, 'status' => $b['perubahan'] === [] && ! $b['nama_beda'] ? 'tetap' : 'lengkapi'];
            }

            [$persis, $mirip] = $this->cocokkan($r['nama'], $kumpulan);
            if ($persis->isEmpty() && $mirip->isEmpty() && ($bentrok = $this->bentrokNomor($r, null))) {
                return $this->error($b, $bentrok);
            }

            // Siswa Dapodik yang tidak ditemukan ditawarkan ditambahkan (bawaan dibuat, bisa dilewati)
            return $this->pilihAtauBaru($b, $persis->concat($mirip));
        })->all();
    }

    private function cocok(array $b, array $s): array
    {
        return [...$b, 'siswa' => $s, 'nama_beda' => Str::squish($b['nama']) !== $s['nama'],
            'bawaan' => ['aksi' => 'cocok', 'siswa_id' => $s['id'], 'nama' => 'aplikasi']];
    }

    // Ada kandidat → TU wajib memilih (cocokkan / buat baru / lewati); tidak ada → siswa baru
    private function pilihAtauBaru(array $b, Collection $kandidat): array
    {
        if ($kandidat->isNotEmpty()) {
            return [...$b, 'status' => 'pilih', 'kandidat' => $kandidat->unique('id')->take(5)->values()->all(),
                'bawaan' => ['aksi' => null, 'siswa_id' => null, 'nama' => 'aplikasi']];
        }

        return [...$b, 'status' => 'baru', 'bawaan' => ['aksi' => 'baru', 'siswa_id' => null, 'nama' => 'file']];
    }

    // [persis, mirip]: persis = nama sama setelah dirapikan (huruf besar-kecil, spasi, tanda baca); mirip = beda sedikit
    // (salah ketik) atau semua kata nama yang lebih pendek ada di nama yang lain (mis. "Angel" dan "Angel Maria")
    private function cocokkan(string $nama, Collection $kumpulan): array
    {
        $kunci = $this->kunci($nama);
        $persis = $kumpulan->filter(fn (array $s) => $s['kunci'] === $kunci)->values();
        $mirip = $kumpulan->reject(fn (array $s) => $s['kunci'] === $kunci)
            ->map(fn (array $s) => [...$s, 'jarak' => levenshtein($kunci, $s['kunci'])])
            ->filter(fn (array $s) => $s['jarak'] <= max(2, intdiv(max(strlen($kunci), strlen($s['kunci'])), 5)) || $this->memuat($kunci, $s['kunci']))
            ->sortBy('jarak')
            ->values();

        return [$persis, $mirip];
    }

    private function memuat(string $a, string $b): bool
    {
        [$pendek, $panjang] = strlen($a) <= strlen($b) ? [$a, $b] : [$b, $a];

        return strlen($pendek) >= 4 && array_diff(explode(' ', $pendek), explode(' ', $panjang)) === [];
    }

    private function kunci(string $nama): string
    {
        return Str::squish(preg_replace('/[^a-z0-9 ]/', ' ', Str::lower(Str::ascii($nama))));
    }

    // Kelas ditulis "1A" atau nama lengkap "1A Gregory"
    private function cariKelas(Collection $kelas, string $tulisan): ?Kelas
    {
        $t = Str::upper(Str::squish($tulisan));

        return $kelas->first(fn (Kelas $k) => Str::upper($k->nama_kelas) === $t || Str::upper(Str::squish($k->nama_lengkap)) === $t)
            ?? $kelas->first(fn (Kelas $k) => Str::startsWith($t, Str::upper($k->nama_kelas).' '));
    }

    // Siswa aktif yang punya kelas di tahun ajaran itu, beserta kunci nama
    private function siswaDiTahun(TahunAjaran $tahunAjaran, bool $tahunIni): Collection
    {
        return SiswaKelas::whereHas('kelas', fn ($q) => $q->where('tahun_ajaran_id', $tahunAjaran->id))
            ->whereHas('siswa', fn ($q) => $q->where('status_sekolah', StatusSekolah::Aktif))
            ->with(['siswa', 'kelas'])
            ->get()
            ->map(fn (SiswaKelas $sk) => [
                'id' => $sk->siswa->id,
                'nama' => $sk->siswa->nama,
                'kunci' => $this->kunci($sk->siswa->nama),
                'kelas_id' => $sk->kelas_id,
                'kelas' => $sk->kelas->nama_lengkap,
                'jenjang' => $sk->kelas->jenjang,
                'tahun_ini' => $tahunIni,
                'nis' => $sk->siswa->nis,
                'nisn' => $sk->siswa->nisn,
                'jk' => $sk->siswa->jenis_kelamin,
                'tanggal_lahir' => $sk->siswa->tanggal_lahir?->toDateString(),
            ])
            ->values();
    }

    private function barisDasar(array $r): array
    {
        return [
            'no' => $r['no'], 'nama' => $r['nama'], 'kelas' => $r['kelas'], 'kelas_id' => null, 'jk' => $r['jk'],
            'status' => null, 'pesan' => null, 'siswa' => null, 'kandidat' => [], 'perubahan' => [], 'nama_beda' => false,
            'bawaan' => ['aksi' => 'lewati', 'siswa_id' => null, 'nama' => 'aplikasi'],
        ];
    }

    private function error(array $b, string $pesan): array
    {
        return [...$b, 'status' => 'error', 'pesan' => $pesan, 'bawaan' => ['aksi' => 'lewati', 'siswa_id' => null, 'nama' => 'aplikasi']];
    }

    // Data Dapodik yang berbeda dengan aplikasi (kolom kosong di Dapodik tidak menghapus data aplikasi)
    private function perubahan(array $s, array $r): array
    {
        $hasil = [];
        foreach (['nis' => 'NIS', 'nisn' => 'NISN', 'tanggal_lahir' => 'Tanggal lahir', 'jk' => 'L/P'] as $kolom => $label) {
            if ($r[$kolom] !== null && (string) $r[$kolom] !== (string) $s[$kolom]) {
                $hasil[] = ['kolom' => $label, 'lama' => $s[$kolom], 'baru' => $r[$kolom]];
            }
        }

        return $hasil;
    }

    // NIS/NISN dari file sudah dipakai siswa lain di aplikasi (termasuk siswa tahun lalu / sudah keluar)
    private function bentrokNomor(array $b, ?int $siswaId): ?string
    {
        // Dimuat sekali per proses (satu query per kolom), bukan per baris — ratusan baris Dapodik di database online
        $this->nomorTerpakai ??= [
            'nisn' => Siswa::withTrashed()->whereNotNull('nisn')->pluck('id', 'nisn')->all(),
            'nis' => Siswa::withTrashed()->whereNotNull('nis')->pluck('id', 'nis')->all(),
        ];

        foreach (['nisn' => 'NISN', 'nis' => 'NIS'] as $kolom => $label) {
            $pemilik = empty($b[$kolom]) ? null : ($this->nomorTerpakai[$kolom][$b[$kolom]] ?? null);
            if ($pemilik !== null && $pemilik !== $siswaId) {
                return "{$label} {$b[$kolom]} sudah dipakai siswa lain di aplikasi.";
            }
        }

        return null;
    }

    // Saat menyimpan dicek ulang langsung ke database (baris sebelumnya di transaksi yang sama bisa sudah memakai nomornya)
    private function pastikanNomorBebas(array $b, ?int $siswaId): void
    {
        $this->nomorTerpakai = null;
        if ($bentrok = $this->bentrokNomor($b, $siswaId)) {
            throw $this->gagal("Baris {$b['no']} ({$b['nama']}): {$bentrok}");
        }
    }

    private function gagal(string $pesan): ValidationException
    {
        return ValidationException::withMessages(['keputusan' => $pesan]);
    }
}
