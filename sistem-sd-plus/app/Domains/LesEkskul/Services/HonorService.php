<?php

namespace App\Domains\LesEkskul\Services;

use App\Domains\LesEkskul\Enums\JenisPengajar;
use App\Domains\LesEkskul\Enums\PeranHonor;
use App\Domains\LesEkskul\Enums\TipeKegiatan;
use App\Domains\LesEkskul\Models\Ekstrakurikuler;
use App\Domains\LesEkskul\Models\HonorDetail;
use App\Domains\LesEkskul\Models\HonorFlatJenis;
use App\Domains\LesEkskul\Models\HonorFlatPengaturan;
use App\Domains\LesEkskul\Models\HonorKegiatan;
use App\Domains\LesEkskul\Models\LesTambahan;
use App\Domains\LesEkskul\Models\PenerimaPeranHonor;
use App\Domains\LesEkskul\Models\PeriodeLaporan;
use App\Domains\LesEkskul\Models\RiwayatWaliKelas;
use App\Domains\LesEkskul\Models\SkemaHonor;
use App\Models\TahunAjaran;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Perhitungan honor per periode (FR-16, FR-16b–d, FR-25b; Ringkasan v1.11 #33–#34).
// Dasar: alokasi pembayaran yang diakui di periode ini saat tutup buku (Catatan v1.8 #3).
// Setiap porsi dibulatkan ke bawah ke rupiah utuh; sisa pembulatan masuk Kas Sekolah sehingga total = dasar honor.
// Skema, penerima peran, dan honor flat memakai pengaturan yang berlaku pada hari honor dihitung (#34): hitung ulang
// dimaksudkan untuk memakai data yang sudah dibetulkan, dan pengaturan pertama kali dibuat setelah periode berakhir
class HonorService
{
    public function __construct(
        private PengajarEkskulService $pengajar,
        private PengaturanHonorService $pengaturan,
        private PembagianPertemuanService $pertemuan,
    ) {}

    public function sudahDihitung(PeriodeLaporan $periode): bool
    {
        return HonorDetail::where('periode_laporan_id', $periode->id)->exists();
    }

    // Ada pendapatan les/ekskul yang diakui atau honor flat bulan ditagih; periode tanpa keduanya (mis. Juni) tidak menghasilkan baris honor
    public function adaYangDihitung(PeriodeLaporan $periode): bool
    {
        $pendapatan = $this->pendapatan($periode);

        return $pendapatan['ekskul']->isNotEmpty() || $pendapatan['les']->isNotEmpty() || $this->pengaturanFlat($periode)->isNotEmpty();
    }

    // Alasan honor belum bisa dihitung; kosong = boleh (#33)
    public function hambatan(PeriodeLaporan $periode): array
    {
        $hambatan = [];

        if (! $periode->sudahDitutup()) {
            $hambatan[] = 'Buku periode ini belum ditutup Bendahara.';
        }

        if ($periode->honor_diketahui_at !== null) {
            $hambatan[] = 'Honor periode ini sudah ditandai "Sudah Diketahui" Kepala Sekolah dan terkunci.';
        }

        $hambatan = [...$hambatan, ...$this->pengaturan->masalahPeran(today())];
        $pendapatan = $this->pendapatan($periode);

        // Hanya kegiatan yang punya pendapatan di periode ini yang harus lengkap datanya
        $pendapatan['ekskul']->pluck('induk')->unique('id')
            ->each(function (Ekstrakurikuler $induk) use (&$hambatan) {
                $hambatan = [...$hambatan, ...$this->pengajar->masalahKegiatan($induk)];
            });

        // Pengajar per bulan tagihan: ada pengajar di setiap bulan dan tidak campur internal/eksternal (#50)
        foreach ($pendapatan['ekskul'] as $u) {
            $hambatan = [...$hambatan, ...$this->pembagianEkskul($u)['masalah']];
        }

        foreach ($this->lesPerJenjang($pendapatan['les']) as $kelasLes) {
            $bulanList = array_keys($this->pendapatanJenjangPerBulan($kelasLes, $pendapatan['les_bulan']));
            foreach ($kelasLes as $les) {
                if (! $les->kelas->waliKelas?->user) {
                    $hambatan[] = "Kelas {$les->kelas->nama_lengkap}: belum punya wali kelas (pengajar les).";
                }
                $hambatan = [...$hambatan, ...$this->pembagianLes($les, $bulanList)['masalah']];
            }
        }

        return array_values(array_unique($hambatan));
    }

    // Hitung (atau hitung ulang) honor periode; hasil lama dihapus lalu dibuat lagi selama belum "Sudah Diketahui"
    public function hitung(PeriodeLaporan $periode): array
    {
        return DB::transaction(function () use ($periode) {
            $periode = PeriodeLaporan::whereKey($periode->id)->lockForUpdate()->firstOrFail();

            if ($hambatan = $this->hambatan($periode)) {
                throw ValidationException::withMessages(['honor' => implode(' ', $hambatan)]);
            }

            // Honor flat hanya diisi dari pengaturan saat pertama kali dihitung; setelah itu diatur Kepala Sekolah per periode
            // (aturHonorFlatPeriode) dan tidak ditimpa hitung ulang (Ringkasan v1.11 #39)
            $pertamaKali = ! $this->sudahDihitung($periode);
            HonorKegiatan::where('periode_laporan_id', $periode->id)->delete(); // rincian persentase ikut terhapus (cascade)

            $pengelola = collect(PengaturanHonorService::PERAN_PENGELOLA)
                ->mapWithKeys(fn (PeranHonor $p) => [$p->value => PenerimaPeranHonor::pemegang($p, today())]);
            $pendapatan = $this->pendapatan($periode);

            foreach ($pendapatan['ekskul'] as $unit) {
                $this->hitungEkskul($periode, $unit, $pengelola);
            }

            foreach ($this->lesPerJenjang($pendapatan['les']) as $kelasLes) {
                $this->hitungLesJenjang($periode, $kelasLes, $pendapatan['les'], $pendapatan['les_bulan'], $pengelola);
            }

            if ($pertamaKali) {
                $this->hitungHonorFlat($periode);
            }

            return [
                'kegiatan' => HonorKegiatan::where('periode_laporan_id', $periode->id)->count(),
                'total' => (int) HonorDetail::where('periode_laporan_id', $periode->id)->whereNotIn('peran', [PeranHonor::KasSekolah, PeranHonor::Yayasan])->sum('nominal'),
            ];
        });
    }

    // Dipanggil saat tutup buku: dihitung bila data lengkap; bila belum, ditahan dan dihitung manual nanti (#33)
    public function hitungBilaLengkap(PeriodeLaporan $periode): bool
    {
        if ($this->hambatan($periode) !== []) {
            return false;
        }

        $this->hitung($periode);

        return true;
    }

    // FR-20: Kepala Sekolah menandai "Sudah Diketahui"; setelah itu honor terkunci dan tidak bisa dihitung ulang
    public function tandaiDiketahui(PeriodeLaporan $periode, User $oleh): void
    {
        DB::transaction(function () use ($periode, $oleh) {
            $periode = PeriodeLaporan::whereKey($periode->id)->lockForUpdate()->firstOrFail();

            // Periode tanpa honor sama sekali boleh langsung ditandai supaya tidak tertahan selamanya
            if (! $this->sudahDihitung($periode) && ($this->adaYangDihitung($periode) || ! $periode->sudahDitutup())) {
                throw ValidationException::withMessages(['honor' => 'Honor periode ini belum dihitung.']);
            }

            if ($periode->honor_diketahui_at !== null) {
                throw ValidationException::withMessages(['honor' => 'Honor periode ini sudah ditandai Sudah Diketahui.']);
            }

            $periode->update(['honor_diketahui_oleh' => $oleh->id, 'honor_diketahui_at' => now()]);
        });
    }

    // Kepala Sekolah mengatur satu jenis honor flat khusus periode ini (#39): tidak dibayar (mis. tidak ada Pramuka bulan ini),
    // atau dibayar ke penerima tertentu dengan jumlah yang bisa berbeda tiap periode (mis. jumlah soal). Nominal per orang
    // tetap dari pengaturan yang berlaku. $penerima = [user_id => jumlah]; kosong = jenis ini tidak dibayar periode ini
    public function aturHonorFlatPeriode(PeriodeLaporan $periode, HonorFlatJenis $jenis, array $penerima): void
    {
        DB::transaction(function () use ($periode, $jenis, $penerima) {
            $periode = PeriodeLaporan::whereKey($periode->id)->lockForUpdate()->firstOrFail();

            $masalah = match (true) {
                $periode->honor_diketahui_at !== null => 'Honor periode ini sudah ditandai Sudah Diketahui dan terkunci.',
                ! $this->sudahDihitung($periode) => 'Hitung honor periode ini dulu, lalu atur honor flatnya.',
                default => null,
            };

            if ($masalah) {
                throw ValidationException::withMessages(['honor' => $masalah]);
            }

            // Baris lama jenis ini dihapus lalu dibuat ulang sesuai pilihan
            HonorDetail::where('periode_laporan_id', $periode->id)
                ->whereHas('honorFlatPengaturan', fn ($q) => $q->where('honor_flat_jenis_id', $jenis->id))
                ->delete();

            if ($penerima === []) {
                return;
            }

            $pengaturan = $this->pengaturanFlatJenis($periode, $jenis)
                ?? throw ValidationException::withMessages(['honor' => "Nominal honor {$jenis->nama} belum diatur di halaman Skema Honor."]);

            foreach ($penerima as $userId => $jumlah) {
                HonorDetail::create([
                    'periode_laporan_id' => $periode->id,
                    'honor_flat_pengaturan_id' => $pengaturan->id,
                    'penerima_user_id' => (int) $userId,
                    'peran' => PeranHonor::HonorFlat,
                    'nominal' => $pengaturan->nominal_per_guru * max(1, (int) $jumlah),
                ]);
            }
        });
    }

    // Honor flat periode ini per jenis untuk diatur Kepala Sekolah: dibayar atau tidak, penerima & jumlahnya
    public function honorFlatPeriode(PeriodeLaporan $periode): Collection
    {
        $baris = HonorDetail::where('periode_laporan_id', $periode->id)
            ->whereNotNull('honor_flat_pengaturan_id')
            ->with('honorFlatPengaturan', 'penerimaUser')
            ->get();

        return HonorFlatJenis::orderBy('id')->get()->map(function (HonorFlatJenis $jenis) use ($periode, $baris) {
            $milikJenis = $baris->filter(fn (HonorDetail $d) => $d->honorFlatPengaturan->honor_flat_jenis_id === $jenis->id);
            $pengaturan = $milikJenis->first()?->honorFlatPengaturan ?? $this->pengaturanFlatJenis($periode, $jenis);
            $nominal = (int) ($pengaturan?->nominal_per_guru ?? 0);

            return [
                'id' => $jenis->id,
                'nama' => $jenis->nama,
                'nominal_per_orang' => $nominal,
                'dibayar' => $milikJenis->isNotEmpty(),
                // jumlah = nominal ÷ nominal per orang (honor flat selalu kelipatan nominal per orang)
                'penerima' => $milikJenis->map(fn (HonorDetail $d) => [
                    'user_id' => $d->penerima_user_id,
                    'nama' => $d->penerimaUser?->name,
                    'jumlah' => $nominal > 0 ? intdiv($d->nominal, $nominal) : 1,
                ])->values(),
                'usulan' => $pengaturan
                    ? $pengaturan->penerima->map(fn (User $u) => ['user_id' => $u->id, 'nama' => $u->name, 'jumlah' => (int) $u->pivot->jumlah])->values()
                    : [],
                'total' => (int) $milikJenis->sum('nominal'),
            ];
        });
    }

    // Rekap 3 layer (FR-19): per kegiatan, per orang per kegiatan, dan rekap akhir per orang seperti rekap honor sekolah
    public function rekap(PeriodeLaporan $periode): array
    {
        $detail = HonorDetail::where('periode_laporan_id', $periode->id)
            ->with(['honorKegiatan.ekstrakurikuler.induk', 'honorKegiatan.lesTambahan.kelas', 'honorFlatPengaturan.jenis', 'penerimaUser', 'penerimaPengajar'])
            ->orderBy('id')
            ->get();

        $kegiatan = HonorKegiatan::where('periode_laporan_id', $periode->id)->with(['ekstrakurikuler', 'lesTambahan.kelas'])->get();
        $catatan = $kegiatan->isEmpty() ? [] : $this->catatanPergantian($periode);

        $kegiatan = $kegiatan
            ->map(fn (HonorKegiatan $k) => [
                'id' => $k->id,
                'nama' => $this->namaKegiatan($k),
                'tipe' => $k->tipe(),
                'total' => $k->total_pendapatan,
                'non_honor' => $k->total_pendapatan - $k->dasar_honor,
                'dasar' => $k->dasar_honor,
                // Pergantian pengajar/wali: "September 2026: A 2 dari 4 pertemuan, B 2 dari 4 pertemuan" (#50)
                'catatan' => $catatan[$k->les_tambahan_id ? 'l'.$k->les_tambahan_id : 'e'.$k->ekstrakurikuler_id] ?? [],
                'rincian' => $detail->where('honor_kegiatan_id', $k->id)->values()->map(fn (HonorDetail $d) => [
                    'peran' => PengaturanHonorService::labelPeran($d->peran),
                    'penerima' => $d->namaPenerima(),
                    'persen' => $k->persen($d->peran) === null ? null : (float) $k->persen($d->peran),
                    'nominal' => $d->nominal,
                ]),
            ])
            ->sortBy(fn (array $k) => ($k['tipe'] === TipeKegiatan::LesTambahan ? '1' : '0').$k['nama'])
            ->values();

        // Kolom rekap akhir: les, ekstra, setiap jenis honor flat, lalu pendamping ekstra (urutan rekap sekolah)
        $kolom = collect([['kode' => 'les', 'label' => 'Les'], ['kode' => 'ekstra', 'label' => 'Ekstra']])
            ->concat(HonorFlatJenis::orderBy('id')->get()->map(fn (HonorFlatJenis $j) => ['kode' => $j->kode, 'label' => $j->nama]))
            ->push(['kode' => 'pendamping', 'label' => 'Pendamping Ekstra']);

        // Kas Sekolah & Yayasan bukan orang; ditampilkan terpisah sebagai ringkasan
        $orang = $detail->filter(fn (HonorDetail $d) => $d->penerima_user_id !== null || $d->penerima_pengajar_id !== null)
            ->groupBy(fn (HonorDetail $d) => $d->penerima_user_id !== null ? 'u'.$d->penerima_user_id : 'p'.$d->penerima_pengajar_id)
            ->map(function (Collection $baris, string $kunci) use ($kolom) {
                $nilai = $kolom->mapWithKeys(fn (array $k) => [$k['kode'] => 0])->all();

                foreach ($baris as $d) {
                    $nilai[$this->kolomRekap($d)] += $d->nominal;
                }

                return [
                    'kunci' => $kunci,
                    'nama' => $baris->first()->namaPenerima(),
                    'eksternal' => $baris->first()->penerima_pengajar_id !== null,
                    'item' => $baris->map(fn (HonorDetail $d) => [
                        'sumber' => $d->honorKegiatan ? $this->namaKegiatan($d->honorKegiatan) : $d->honorFlatPengaturan->jenis->nama,
                        'peran' => PengaturanHonorService::labelPeran($d->peran),
                        'nominal' => $d->nominal,
                    ])->values(),
                    'nilai' => $nilai,
                    'jumlah' => $baris->sum('nominal'),
                ];
            })
            ->sortBy('nama', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        return [
            'kegiatan' => $kegiatan,
            'orang' => $orang,
            'kolom' => $kolom,
            'total_kolom' => $kolom->mapWithKeys(fn (array $k) => [$k['kode'] => $orang->sum(fn (array $o) => $o['nilai'][$k['kode']])]),
            'total_orang' => $orang->sum('jumlah'),
            'kas_sekolah' => $detail->where('peran', PeranHonor::KasSekolah)->sum('nominal'),
            'yayasan' => $detail->where('peran', PeranHonor::Yayasan)->sum('nominal'),
            'pendapatan' => $kegiatan->sum('total'),
            'non_honor' => $kegiatan->sum('non_honor'),
        ];
    }

    // Keterangan pembagian per pertemuan untuk bulan yang pengajarnya tidak hadir penuh (ada pergantian), per unit honor
    private function catatanPergantian(PeriodeLaporan $periode): array
    {
        $teks = function (array $hadir, callable $nama): array {
            $hasil = [];
            foreach ($hadir as $bulan => $h) {
                $jumlah = $h['jumlah'];
                unset($h['jumlah']);
                if (min($h) < $jumlah) {
                    $orang = collect($h)->map(fn (int $n, int $kunci) => $nama($kunci)." {$n} dari {$jumlah} pertemuan")->implode(', ');
                    $hasil[] = CarbonImmutable::parse($bulan)->locale('id')->translatedFormat('F Y').": {$orang}";
                }
            }

            return $hasil;
        };

        $pendapatan = $this->pendapatan($periode);
        $catatan = [];

        foreach ($pendapatan['ekskul'] as $u) {
            $p = $this->pembagianEkskul($u);
            $catatan['e'.$u->unit->id] = $teks($p['hadir'], fn (int $id) => $p['pengajar'][$id]->nama ?? "#{$id}");
        }

        foreach ($this->lesPerJenjang($pendapatan['les']) as $kelasLes) {
            $bulanList = array_keys($this->pendapatanJenjangPerBulan($kelasLes, $pendapatan['les_bulan']));
            foreach ($kelasLes as $les) {
                $p = $this->pembagianLes($les, $bulanList);
                $catatan['l'.$les->id] = $teks($p['hadir'], fn (int $id) => $p['wali'][$id]->user->name ?? "#{$id}");
            }
        }

        return array_filter($catatan);
    }

    private function namaKegiatan(HonorKegiatan $k): string
    {
        return $k->lesTambahan ? 'Les Kelas '.$k->lesTambahan->kelas->nama_lengkap : $k->ekstrakurikuler->nama;
    }

    private function kolomRekap(HonorDetail $d): string
    {
        return match (true) {
            $d->honor_flat_pengaturan_id !== null => $d->honorFlatPengaturan->jenis->kode,
            $d->peran === PeranHonor::GuruPendamping => 'pendamping',
            $d->honorKegiatan->les_tambahan_id !== null => 'les',
            default => 'ekstra',
        };
    }

    // Pendapatan yang diakui periode ini, dikelompokkan per unit honor.
    // ekskul: [unit (Ekstrakurikuler), induk, total, non_honor, per_bulan]; les: [les_tambahan_id => total];
    // les_bulan: [les_tambahan_id => [bulan => total]]. per_bulan = pendapatan per bulan tagihan, dasar pembagian
    // honor ke pengajar pada bulan tagihannya (#50)
    private function pendapatan(PeriodeLaporan $periode): array
    {
        $rinci = DB::table('alokasi_transaksi as a')
            ->join('tagihan as t', 't.id', '=', 'a.tagihan_id')
            ->join('pendaftaran as p', 'p.id', '=', 't.pendaftaran_id')
            ->where('a.diakui_periode_id', $periode->id)
            ->groupBy('p.les_tambahan_id', 'p.ekstrakurikuler_id', 't.bulan_tagihan')
            ->get(['p.les_tambahan_id', 'p.ekstrakurikuler_id', 't.bulan_tagihan', DB::raw('SUM(a.nominal) as total')]);

        // Total per kegiatan tetap dihitung seperti sebelumnya (bagian non-honor per baris kegiatan), baru dipecah per bulan
        $baris = $rinci->groupBy(fn ($b) => $b->les_tambahan_id.'-'.$b->ekstrakurikuler_id)
            ->map(fn (Collection $c) => (object) [
                'les_tambahan_id' => $c->first()->les_tambahan_id,
                'ekstrakurikuler_id' => $c->first()->ekstrakurikuler_id,
                'total' => $c->sum(fn ($b) => (int) $b->total),
                'per_bulan' => $c->mapWithKeys(fn ($b) => [substr((string) $b->bulan_tagihan, 0, 10) => (int) $b->total])->all(),
            ])
            ->values();

        $ekskul = Ekstrakurikuler::whereIn('id', $baris->pluck('ekstrakurikuler_id')->filter())
            ->with(['induk.kelasParalel.riwayatPengajar', 'induk.riwayatPengajar', 'induk.pendamping', 'induk.jadwal', 'kelasParalel.riwayatPengajar', 'riwayatPengajar', 'pendamping', 'jadwal'])
            ->get()
            ->keyBy('id');

        $unit = [];
        foreach ($baris->whereNotNull('ekstrakurikuler_id') as $b) {
            $kegiatan = $ekskul[$b->ekstrakurikuler_id];
            $induk = $kegiatan->induk ?? $kegiatan;
            if ($induk->honor_flat) {
                continue; // kegiatan honor flat (mis. Olimpiade) tidak memakai skema persen (FR-25)
            }
            // Per kelas: unit = kelas siswa; digabung: unit = kegiatan induk (FR-16b, #33)
            $u = $induk->honor_per_kelas_paralel && $induk->kelasParalel->isNotEmpty() ? $kegiatan : $induk;
            $total = $b->total;
            // Bagian non-honor (mis. uang kolam) sebanding dengan pembayaran: biaya_non_honor / biaya
            $nonHonor = $induk->biaya > 0 ? intdiv($total * (int) $induk->biaya_non_honor, (int) $induk->biaya) : 0;

            $unit[$u->id] ??= ['unit' => $u, 'induk' => $induk, 'total' => 0, 'non_honor' => 0, 'per_bulan' => []];
            $unit[$u->id]['total'] += $total;
            $unit[$u->id]['non_honor'] += $nonHonor;
            foreach ($b->per_bulan as $bulan => $nominal) {
                $unit[$u->id]['per_bulan'][$bulan] = ($unit[$u->id]['per_bulan'][$bulan] ?? 0) + $nominal;
            }
        }

        $les = $baris->whereNotNull('les_tambahan_id');

        return [
            'ekskul' => collect($unit)->map(fn (array $u) => (object) $u)->values(),
            'les' => $les->mapWithKeys(fn ($b) => [$b->les_tambahan_id => $b->total]),
            'les_bulan' => $les->mapWithKeys(fn ($b) => [$b->les_tambahan_id => $b->per_bulan]),
        ];
    }

    // Pembagian porsi pengajar sebuah unit ekskul per bulan tagihan (#50). Baris tempat pengajar dicatat sama dengan
    // pengajarUnit(); pertemuan = hari jadwal kegiatan induk. Hasil: pengajar (keyed id), bobot [bulan => [id => bobot]],
    // hadir [bulan => [id => pertemuan]] + jumlah pertemuan, dan masalah yang menahan perhitungan
    private function pembagianEkskul(object $u): array
    {
        $unit = $u->unit;
        $baris = $unit->adalahKelasParalel() || $unit->kelasParalel->isEmpty() || $unit->honor_per_kelas_paralel
            ? collect([$unit])
            : $unit->kelasParalel->concat([$unit]);

        $riwayat = $baris->flatMap(fn (Ekstrakurikuler $e) => $e->riwayatPengajar);
        $penugasan = $riwayat->map(fn ($p) => [
            'kunci' => $p->id,
            'mulai' => $p->pivot->tanggal_mulai ? substr((string) $p->pivot->tanggal_mulai, 0, 10) : null,
            'selesai' => $p->pivot->tanggal_selesai ? substr((string) $p->pivot->tanggal_selesai, 0, 10) : null,
        ]);
        $hari = $u->induk->jadwal->concat($unit->jadwal)->pluck('hari')->all();

        $hasil = ['pengajar' => $riwayat->unique('id')->keyBy('id'), 'bobot' => [], 'hadir' => [], 'masalah' => []];
        foreach (array_keys($u->per_bulan) as $bulan) {
            $b = $this->pertemuan->bobot($penugasan, $this->pertemuan->tanggalPertemuan(CarbonImmutable::parse($bulan), $hari));
            if ($b['bobot'] === []) {
                $hasil['masalah'][] = "{$unit->nama}: tidak ada pengajar yang tercatat mengajar pada ".CarbonImmutable::parse($bulan)->locale('id')->translatedFormat('F Y').'.';

                continue;
            }
            $hasil['bobot'][$bulan] = $b['bobot'];
            $hasil['hadir'][$bulan] = $b['hadir'] + ['jumlah' => $b['jumlah']];
        }

        // Hanya pengajar yang benar-benar mendapat bagian di periode ini yang menentukan skema internal/eksternal
        $dipakai = collect($hasil['bobot'])->flatMap(fn (array $b) => array_keys($b))->unique();
        $hasil['pengajar'] = $hasil['pengajar']->only($dipakai->all());
        if ($hasil['pengajar']->pluck('jenis')->unique()->count() > 1) {
            $hasil['masalah'][] = "{$unit->nama}: pengajar internal dan eksternal tercampur di periode ini (pergantian pengajar beda jenis); honor belum bisa dihitung.";
        }

        return $hasil;
    }

    // Wali kelas sebuah kelas les per bulan tagihan (#50): riwayat_wali_kelas bila pernah berganti, selain itu wali sekarang.
    // Pertemuan les = hari sekolah Senin–Jumat. Hasil: wali (guru keyed id), bobot, hadir, masalah
    private function pembagianLes(LesTambahan $les, array $bulanList): array
    {
        $riwayat = RiwayatWaliKelas::where('kelas_id', $les->kelas_id)->with('guru.user')->get();
        $penugasan = $riwayat->isNotEmpty()
            ? $riwayat->map(fn (RiwayatWaliKelas $r) => ['kunci' => $r->guru_id, 'mulai' => $r->tanggal_mulai?->toDateString(), 'selesai' => $r->tanggal_selesai?->toDateString()])
            : collect($les->kelas->wali_kelas_id ? [['kunci' => $les->kelas->wali_kelas_id, 'mulai' => null, 'selesai' => null]] : []);
        $guru = $riwayat->isNotEmpty() ? $riwayat->pluck('guru')->unique('id')->keyBy('id') : collect($les->kelas->waliKelas ? [$les->kelas->waliKelas->id => $les->kelas->waliKelas] : []);

        $hasil = ['wali' => $guru, 'bobot' => [], 'hadir' => [], 'masalah' => []];
        foreach ($bulanList as $bulan) {
            $b = $this->pertemuan->bobot($penugasan, $this->pertemuan->hariSekolah(CarbonImmutable::parse($bulan)));
            if ($b['bobot'] === []) {
                $hasil['masalah'][] = "Kelas {$les->kelas->nama_lengkap}: tidak ada wali kelas yang tercatat pada ".CarbonImmutable::parse($bulan)->locale('id')->translatedFormat('F Y').'.';

                continue;
            }
            $hasil['bobot'][$bulan] = $b['bobot'];
            $hasil['hadir'][$bulan] = $b['hadir'] + ['jumlah' => $b['jumlah']];
        }

        foreach ($guru->only(collect($hasil['bobot'])->flatMap(fn (array $b) => array_keys($b))->unique()->all()) as $g) {
            if (! $g->user) {
                $hasil['masalah'][] = "Kelas {$les->kelas->nama_lengkap}: wali kelas tanpa akun pengguna.";
            }
        }

        return $hasil;
    }

    // Pendapatan les satu jenjang per bulan tagihan: dasar penimbang pembagian pool pengajar les ke wali per kelas
    private function pendapatanJenjangPerBulan(Collection $kelasLes, Collection $lesBulan): array
    {
        $hasil = [];
        foreach ($kelasLes as $les) {
            foreach ($lesBulan[$les->id] ?? [] as $bulan => $nominal) {
                $hasil[$bulan] = ($hasil[$bulan] ?? 0) + $nominal;
            }
        }
        ksort($hasil);

        return $hasil;
    }

    private function hitungEkskul(PeriodeLaporan $periode, object $u, Collection $pengelola): void
    {
        $pembagian = $this->pembagianEkskul($u);
        $pengajar = $pembagian['pengajar'];
        $jenis = $pengajar->first()->jenis;
        $skema = $this->skema(TipeKegiatan::Ekstrakurikuler, $jenis, $periode);
        $dasar = $u->total - $u->non_honor;

        $kegiatan = HonorKegiatan::create([
            'periode_laporan_id' => $periode->id,
            'ekstrakurikuler_id' => $u->unit->id,
            'total_pendapatan' => $u->total,
            'dasar_honor' => $dasar,
            'snapshot_persen' => $skema->snapshot(),
        ]);

        // Porsi pengajar dibagi rata ke pengajar unik (FR-16b); bila pengajar berganti, per pertemuan bulan tagihannya (#50).
        // Internal lewat akunnya, eksternal lewat data pengajar
        $bagian = $this->pertemuan->bagi($this->porsi($dasar, $skema->persen_pengajar), $pembagian['bobot'], $u->per_bulan);
        $rincian = $pengajar->map(fn ($p) => [
            PeranHonor::Pengajar,
            $p->jenis === JenisPengajar::Internal ? $p->user_id : null,
            $p->jenis === JenisPengajar::Eksternal ? $p->id : null,
            $bagian[$p->id] ?? 0,
        ])->values()->all();

        // Setiap pendamping menerima porsi pendamping penuh; pendamping kedua dst. diambil dari kas (FR-16d).
        // Skema internal tidak punya porsi pendamping (sudah termasuk porsi pengajar, #33)
        if ((float) $skema->persen_guru_pendamping > 0) {
            foreach ($u->unit->pendamping as $pendamping) {
                $rincian[] = [PeranHonor::GuruPendamping, $pendamping->id, null, $this->porsi($dasar, $skema->persen_guru_pendamping)];
            }
        }

        $this->simpanRincian($kegiatan, $rincian, $skema, $dasar, $pengelola);
    }

    // Les satu jenjang: honor_kegiatan per kelas, porsi pengajar seluruh kelas dijumlah lalu dibagi rata ke wali kelasnya (FR-16c)
    private function hitungLesJenjang(PeriodeLaporan $periode, Collection $kelasLes, Collection $pendapatan, Collection $lesBulan, Collection $pengelola): void
    {
        $perBulan = $this->pendapatanJenjangPerBulan($kelasLes, $lesBulan);
        $skema = $this->skema(TipeKegiatan::LesTambahan, null, $periode);
        $kegiatan = $kelasLes->mapWithKeys(fn (LesTambahan $les) => [$les->id => HonorKegiatan::create([
            'periode_laporan_id' => $periode->id,
            'les_tambahan_id' => $les->id,
            'total_pendapatan' => $pendapatan[$les->id] ?? 0,
            'dasar_honor' => $pendapatan[$les->id] ?? 0,
            'snapshot_persen' => $skema->snapshot(),
        ])]);

        $poolPengajar = $kegiatan->sum(fn (HonorKegiatan $k) => $this->porsi($k->dasar_honor, $skema->persen_pengajar));
        $perWali = intdiv($poolPengajar, $kelasLes->count());
        $sisa = $poolPengajar - $perWali * $kelasLes->count();

        foreach ($kelasLes as $les) {
            $k = $kegiatan[$les->id];
            // Bagian kelas ini untuk wali kelasnya; bila wali berganti, dibagi per hari sekolah bulan tagihannya (#50)
            $pembagian = $this->pembagianLes($les, array_keys($perBulan));
            $bagian = $this->pertemuan->bagi($perWali, $pembagian['bobot'], $perBulan);
            $rincian = collect($bagian)->map(fn (int $nominal, int $guruId) => [PeranHonor::Pengajar, $pembagian['wali'][$guruId]->user->id, null, $nominal])->values()->all();
            $sisa += $perWali - array_sum($bagian);

            // Porsi pengajar kelas ini sudah masuk pool; selisih pool vs porsi kelas diserap kas kelas pertama
            $this->simpanRincian($k, $rincian, $skema, $k->dasar_honor, $pengelola, $this->porsi($k->dasar_honor, $skema->persen_pengajar));
        }

        // Sisa pembagian pool (beberapa rupiah) masuk kas kelas pertama supaya total jenjang = jumlah dasar honor
        if ($sisa > 0) {
            HonorDetail::where('honor_kegiatan_id', $kegiatan->first()->id)->where('peran', PeranHonor::KasSekolah)->increment('nominal', $sisa);
        }
    }

    // Simpan rincian pengajar/pendamping + yayasan + pengelola; kas = dasar dikurangi semua porsi lain (menyerap pembulatan).
    // $porsiPengajarKegiatan: untuk les, porsi pengajar milik kelas ini (rincian pengajarnya memakai hasil pool jenjang)
    private function simpanRincian(HonorKegiatan $kegiatan, array $rincian, SkemaHonor $skema, int $dasar, Collection $pengelola, ?int $porsiPengajarKegiatan = null): void
    {
        if ((float) $skema->persen_yayasan > 0) {
            $rincian[] = [PeranHonor::Yayasan, null, null, $this->porsi($dasar, $skema->persen_yayasan)];
        }

        foreach (PengaturanHonorService::PERAN_PENGELOLA as $peran) {
            $rincian[] = [$peran, $pengelola[$peran->value]->id, null, $this->porsi($dasar, $skema->{'persen_'.$peran->value})];
        }

        $dipakai = collect($rincian)->sum(fn (array $r) => $r[3]);
        if ($porsiPengajarKegiatan !== null) {
            $pengajarDiRincian = collect($rincian)->where(0, PeranHonor::Pengajar)->sum(fn (array $r) => $r[3]);
            $dipakai = $dipakai - $pengajarDiRincian + $porsiPengajarKegiatan;
        }
        $rincian[] = [PeranHonor::KasSekolah, null, null, $dasar - $dipakai];

        foreach ($rincian as [$peran, $userId, $pengajarId, $nominal]) {
            if ($nominal !== 0 || $peran === PeranHonor::KasSekolah) {
                HonorDetail::create([
                    'honor_kegiatan_id' => $kegiatan->id,
                    'penerima_user_id' => $userId,
                    'penerima_pengajar_id' => $pengajarId,
                    'peran' => $peran,
                    'nominal' => $nominal,
                ]);
            }
        }
    }

    // Honor flat hanya untuk periode yang bulannya ditagih (#33): nominal per orang x jumlah (FR-25b)
    private function hitungHonorFlat(PeriodeLaporan $periode): void
    {
        foreach ($this->pengaturanFlat($periode) as $pengaturan) {
            foreach ($pengaturan->penerima as $penerima) {
                HonorDetail::create([
                    'periode_laporan_id' => $periode->id,
                    'honor_flat_pengaturan_id' => $pengaturan->id,
                    'penerima_user_id' => $penerima->id,
                    'peran' => PeranHonor::HonorFlat,
                    'nominal' => $pengaturan->nominal_per_guru * (int) $penerima->pivot->jumlah,
                ]);
            }
        }
    }

    // Pengaturan honor flat yang berlaku di akhir periode dan punya nominal & penerima; kosong untuk bulan tidak ditagih
    private function pengaturanFlat(PeriodeLaporan $periode): Collection
    {
        if (in_array($periode->bulan->month, config('les_ekskul.bulan_tidak_ditagih'), true)) {
            return collect();
        }

        return HonorFlatJenis::orderBy('id')->get()
            ->map(fn (HonorFlatJenis $jenis) => $this->pengaturanFlatJenis($periode, $jenis))
            ->filter(fn ($p) => $p && $p->penerima->isNotEmpty())
            ->values();
    }

    // Pengaturan satu jenis honor flat yang berlaku untuk tahun ajaran periode ini dan punya nominal; null bila belum diatur
    private function pengaturanFlatJenis(PeriodeLaporan $periode, HonorFlatJenis $jenis): ?HonorFlatPengaturan
    {
        $tahunAjaran = TahunAjaran::where('tanggal_mulai', '<=', $periode->tanggal_selesai->toDateString())
            ->where('tanggal_selesai', '>=', $periode->tanggal_selesai->toDateString())
            ->first();
        $pengaturan = $tahunAjaran ? $this->pengaturan->honorFlatBerlaku($jenis, $tahunAjaran, today()) : null;

        return $pengaturan && $pengaturan->nominal_per_guru > 0 ? $pengaturan : null;
    }

    // Kelas les per jenjang: semua kelas les di jenjang yang punya pendapatan ikut dibagi (FR-16c)
    private function lesPerJenjang(Collection $pendapatanLes): Collection
    {
        if ($pendapatanLes->isEmpty()) {
            return collect();
        }

        $berpendapatan = LesTambahan::whereIn('id', $pendapatanLes->keys())->with('kelas')->get();

        return LesTambahan::query()
            ->whereIn('tahun_ajaran_id', $berpendapatan->pluck('tahun_ajaran_id')->unique())
            ->with('kelas.waliKelas.user')
            ->get()
            ->filter(fn (LesTambahan $les) => $berpendapatan->contains(fn ($b) => $b->tahun_ajaran_id === $les->tahun_ajaran_id && $b->kelas->jenjang === $les->kelas->jenjang))
            ->sortBy(fn (LesTambahan $les) => $les->kelas->nama_kelas)
            ->groupBy(fn (LesTambahan $les) => $les->tahun_ajaran_id.'-'.$les->kelas->jenjang)
            ->sortKeys()
            ->map(fn (Collection $c) => $c->values());
    }

    private function skema(TipeKegiatan $tipe, ?JenisPengajar $jenis, PeriodeLaporan $periode): SkemaHonor
    {
        return SkemaHonor::berlakuPada($tipe, $jenis, today())
            ?? throw ValidationException::withMessages(['honor' => 'Skema honor belum diatur untuk periode ini.']);
    }

    // Porsi persen dari dasar, dibulatkan ke bawah ke rupiah utuh; dihitung dengan bilangan bulat (persen x 100) supaya tidak ada galat desimal
    private function porsi(int $dasar, $persen): int
    {
        return intdiv($dasar * (int) round(((float) $persen) * 100), 10000);
    }
}
