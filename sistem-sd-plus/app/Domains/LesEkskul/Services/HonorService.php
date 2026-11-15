<?php

namespace App\Domains\LesEkskul\Services;

use App\Domains\LesEkskul\Enums\JenisPengajar;
use App\Domains\LesEkskul\Enums\PeranHonor;
use App\Domains\LesEkskul\Enums\TipeKegiatan;
use App\Domains\LesEkskul\Models\Ekstrakurikuler;
use App\Domains\LesEkskul\Models\HonorDetail;
use App\Domains\LesEkskul\Models\HonorFlatJenis;
use App\Domains\LesEkskul\Models\HonorKegiatan;
use App\Domains\LesEkskul\Models\LesTambahan;
use App\Domains\LesEkskul\Models\PenerimaPeranHonor;
use App\Domains\LesEkskul\Models\PeriodeLaporan;
use App\Domains\LesEkskul\Models\SkemaHonor;
use App\Models\TahunAjaran;
use App\Models\User;
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
    public function __construct(private PengajarEkskulService $pengajar, private PengaturanHonorService $pengaturan) {}

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

        foreach ($this->lesPerJenjang($pendapatan['les']) as $kelasLes) {
            foreach ($kelasLes as $les) {
                if (! $les->kelas->waliKelas?->user) {
                    $hambatan[] = "Kelas {$les->kelas->nama_lengkap}: belum punya wali kelas (pengajar les).";
                }
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

            HonorKegiatan::where('periode_laporan_id', $periode->id)->delete(); // rincian persentase ikut terhapus (cascade)
            HonorDetail::where('periode_laporan_id', $periode->id)->delete(); // sisa: rincian honor flat

            $pengelola = collect(PengaturanHonorService::PERAN_PENGELOLA)
                ->mapWithKeys(fn (PeranHonor $p) => [$p->value => PenerimaPeranHonor::pemegang($p, today())]);
            $pendapatan = $this->pendapatan($periode);

            foreach ($pendapatan['ekskul'] as $unit) {
                $this->hitungEkskul($periode, $unit, $pengelola);
            }

            foreach ($this->lesPerJenjang($pendapatan['les']) as $kelasLes) {
                $this->hitungLesJenjang($periode, $kelasLes, $pendapatan['les'], $pengelola);
            }

            $this->hitungHonorFlat($periode);

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

    // Rekap 3 layer (FR-19): per kegiatan, per orang per kegiatan, dan rekap akhir per orang seperti rekap honor sekolah
    public function rekap(PeriodeLaporan $periode): array
    {
        $detail = HonorDetail::where('periode_laporan_id', $periode->id)
            ->with(['honorKegiatan.ekstrakurikuler.induk', 'honorKegiatan.lesTambahan.kelas', 'honorFlatPengaturan.jenis', 'penerimaUser', 'penerimaPengajar'])
            ->orderBy('id')
            ->get();

        $kegiatan = HonorKegiatan::where('periode_laporan_id', $periode->id)
            ->with(['ekstrakurikuler', 'lesTambahan.kelas'])
            ->get()
            ->map(fn (HonorKegiatan $k) => [
                'id' => $k->id,
                'nama' => $this->namaKegiatan($k),
                'tipe' => $k->tipe(),
                'total' => $k->total_pendapatan,
                'non_honor' => $k->total_pendapatan - $k->dasar_honor,
                'dasar' => $k->dasar_honor,
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
    // ekskul: [unit (Ekstrakurikuler), induk, total, non_honor]; les: [les_tambahan_id => total]
    private function pendapatan(PeriodeLaporan $periode): array
    {
        $baris = DB::table('alokasi_transaksi as a')
            ->join('tagihan as t', 't.id', '=', 'a.tagihan_id')
            ->join('pendaftaran as p', 'p.id', '=', 't.pendaftaran_id')
            ->where('a.diakui_periode_id', $periode->id)
            ->groupBy('p.les_tambahan_id', 'p.ekstrakurikuler_id')
            ->get(['p.les_tambahan_id', 'p.ekstrakurikuler_id', DB::raw('SUM(a.nominal) as total')]);

        $ekskul = Ekstrakurikuler::whereIn('id', $baris->pluck('ekstrakurikuler_id')->filter())
            ->with(['induk.kelasParalel', 'induk.pengajar', 'induk.pendamping', 'kelasParalel', 'pengajar', 'pendamping'])
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
            $total = (int) $b->total;
            // Bagian non-honor (mis. uang kolam) sebanding dengan pembayaran: biaya_non_honor / biaya
            $nonHonor = $induk->biaya > 0 ? intdiv($total * (int) $induk->biaya_non_honor, (int) $induk->biaya) : 0;

            $unit[$u->id] ??= ['unit' => $u, 'induk' => $induk, 'total' => 0, 'non_honor' => 0];
            $unit[$u->id]['total'] += $total;
            $unit[$u->id]['non_honor'] += $nonHonor;
        }

        return [
            'ekskul' => collect($unit)->map(fn (array $u) => (object) $u)->values(),
            'les' => $baris->whereNotNull('les_tambahan_id')->mapWithKeys(fn ($b) => [$b->les_tambahan_id => (int) $b->total]),
        ];
    }

    private function hitungEkskul(PeriodeLaporan $periode, object $u, Collection $pengelola): void
    {
        $pengajar = $this->pengajar->pengajarUnit($u->unit);
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

        // Porsi pengajar dibagi rata ke pengajar unik; internal lewat akunnya, eksternal lewat data pengajar (FR-16b)
        $perOrang = intdiv($this->porsi($dasar, $skema->persen_pengajar), $pengajar->count());
        $rincian = $pengajar->map(fn ($p) => [
            PeranHonor::Pengajar,
            $p->jenis === JenisPengajar::Internal ? $p->user_id : null,
            $p->jenis === JenisPengajar::Eksternal ? $p->id : null,
            $perOrang,
        ])->all();

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
    private function hitungLesJenjang(PeriodeLaporan $periode, Collection $kelasLes, Collection $pendapatan, Collection $pengelola): void
    {
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

        foreach ($kelasLes as $les) {
            $k = $kegiatan[$les->id];
            $rincian = [[PeranHonor::Pengajar, $les->kelas->waliKelas->user->id, null, $perWali]];

            // Porsi pengajar kelas ini sudah masuk pool; selisih pool vs porsi kelas diserap kas kelas pertama
            $this->simpanRincian($k, $rincian, $skema, $k->dasar_honor, $pengelola, $this->porsi($k->dasar_honor, $skema->persen_pengajar));
        }

        // Sisa pembagian pool (beberapa rupiah) masuk kas kelas pertama supaya total jenjang = jumlah dasar honor
        $sisa = $poolPengajar - $perWali * $kelasLes->count();
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
        $tahunAjaran = TahunAjaran::where('tanggal_mulai', '<=', $periode->tanggal_selesai->toDateString())
            ->where('tanggal_selesai', '>=', $periode->tanggal_selesai->toDateString())
            ->first();

        if (! $tahunAjaran || in_array($periode->bulan->month, config('les_ekskul.bulan_tidak_ditagih'), true)) {
            return collect();
        }

        return HonorFlatJenis::orderBy('id')->get()
            ->map(fn (HonorFlatJenis $jenis) => $this->pengaturan->honorFlatBerlaku($jenis, $tahunAjaran, today()))
            ->filter(fn ($p) => $p && $p->nominal_per_guru > 0 && $p->penerima->isNotEmpty())
            ->values();
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
