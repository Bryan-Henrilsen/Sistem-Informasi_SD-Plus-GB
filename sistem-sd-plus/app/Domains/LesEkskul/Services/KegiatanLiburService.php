<?php

namespace App\Domains\LesEkskul\Services;

use App\Domains\LesEkskul\Enums\JenisPenyesuaian;
use App\Domains\LesEkskul\Enums\StatusPembayaranOnline;
use App\Domains\LesEkskul\Enums\StatusPenyesuaian;
use App\Domains\LesEkskul\Enums\StatusPeriodeLaporan;
use App\Domains\LesEkskul\Enums\StatusTagihan;
use App\Domains\LesEkskul\Enums\StatusTransaksi;
use App\Domains\LesEkskul\Models\Ekstrakurikuler;
use App\Domains\LesEkskul\Models\KegiatanLibur;
use App\Domains\LesEkskul\Models\LesTambahan;
use App\Domains\LesEkskul\Models\Pendaftaran;
use App\Domains\LesEkskul\Models\PenyesuaianDana;
use App\Domains\LesEkskul\Models\PeriodeLaporan;
use App\Domains\LesEkskul\Models\Tagihan;
use App\Models\TahunAjaran;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Kegiatan tidak berlangsung sebulan penuh (FR-23, Ringkasan v1.11 #48). Tagihan bulan itu dibatalkan; uang yang sudah
// membayarnya dialihkan ke bulan ditagih berikutnya untuk kegiatan yang sama sampai akhir tahun ajaran, sisanya direfund
class KegiatanLiburService
{
    public function __construct(
        private TagihanService $tagihan,
        private TransaksiService $transaksi,
    ) {}

    // Daftar penandaan tahun ajaran ini, bulan terbaru dulu
    public function daftar(TahunAjaran $tahunAjaran): Collection
    {
        $bulanTerkunci = $this->bulanTerakhirTutupBuku();

        return KegiatanLibur::query()
            ->whereBetween('bulan', [$tahunAjaran->tanggal_mulai->startOfMonth()->toDateString(), $tahunAjaran->tanggal_selesai->toDateString()])
            ->with(['lesTambahan.kelas', 'ekstrakurikuler.kelasParalel', 'dicatatOlehUser:id,name'])
            ->orderByDesc('bulan')
            ->orderByDesc('id')
            ->get()
            ->map(fn (KegiatanLibur $l) => [
                'id' => $l->id,
                'kegiatan' => $l->nama_kegiatan,
                // Ekskul induk yang punya kelas paralel: penandaan berlaku untuk semua kelasnya
                'termasuk_kelas_paralel' => $l->ekstrakurikuler?->kelasParalel->isNotEmpty() ?? false,
                'bulan' => $l->bulan->format('Y-m'),
                'alasan' => $l->alasan,
                'jumlah_tagihan' => $l->jumlah_tagihan,
                'dialihkan' => $l->nominal_dialihkan,
                'refund' => $l->nominal_refund,
                'dicatat_oleh' => $l->dicatatOlehUser->name,
                'dicatat' => $l->created_at->toDateString(),
                'bisa_dibatalkan' => ! $l->adaUangBerpindah() && ! ($bulanTerkunci && $l->bulan->lte($bulanTerkunci)),
            ]);
    }

    // Pilihan kegiatan berbiaya: les tambahan per kelas, ekskul induk (semua kelas paralel), dan tiap kelas paralel
    public function pilihanKegiatan(TahunAjaran $tahunAjaran): array
    {
        $les = LesTambahan::where('tahun_ajaran_id', $tahunAjaran->id)
            ->where('biaya', '>', 0)
            ->with('kelas')
            ->get()
            ->sortBy(fn (LesTambahan $l) => sprintf('%d %s', $l->kelas->jenjang, $l->kelas->nama_kelas))
            ->map(fn (LesTambahan $l) => ['kunci' => "les-{$l->id}", 'nama' => 'Les Tambahan '.$l->kelas->nama_lengkap, 'kelompok' => 'Les Tambahan'])
            ->values();

        $ekskul = Ekstrakurikuler::where('tahun_ajaran_id', $tahunAjaran->id)
            ->kegiatanInduk()
            ->where('biaya', '>', 0)
            ->with(['kelasParalel' => fn ($q) => $q->orderBy('nama')])
            ->orderBy('nama')
            ->get()
            ->flatMap(fn (Ekstrakurikuler $e) => [
                ['kunci' => "ekskul-{$e->id}", 'nama' => $e->kelasParalel->isEmpty() ? $e->nama : "{$e->nama} (semua kelas)", 'kelompok' => 'Ekstrakurikuler'],
                ...$e->kelasParalel->map(fn (Ekstrakurikuler $k) => ['kunci' => "ekskul-{$k->id}", 'nama' => $k->nama, 'kelompok' => 'Ekstrakurikuler']),
            ]);

        return [...$les, ...$ekskul];
    }

    // Bulan yang boleh ditandai: bulan ditagih di tahun ajaran ini yang belum tutup buku ("Y-m")
    public function pilihanBulan(TahunAjaran $tahunAjaran): array
    {
        $bulanTerkunci = $this->bulanTerakhirTutupBuku();
        $bulan = $tahunAjaran->tanggal_mulai->startOfMonth();
        $hasil = [];

        while ($bulan->lte($tahunAjaran->tanggal_selesai)) {
            if (! in_array($bulan->month, config('les_ekskul.bulan_tidak_ditagih'), true) && ! ($bulanTerkunci && $bulan->lte($bulanTerkunci))) {
                $hasil[] = $bulan->format('Y-m');
            }
            $bulan = $bulan->addMonth();
        }

        return $hasil;
    }

    // Pratinjau = proses sebenarnya di dalam transaksi database yang lalu dibatalkan, jadi angkanya sama dengan hasil simpan
    public function pratinjau(array $data, User $oleh): array
    {
        DB::beginTransaction();

        try {
            return $this->proses($data, $oleh)['hasil'];
        } finally {
            DB::rollBack();
        }
    }

    public function tandai(array $data, User $oleh): KegiatanLibur
    {
        return DB::transaction(fn () => $this->proses($data, $oleh)['libur']);
    }

    // Batalkan penandaan: hanya bila tidak ada uang yang dialihkan/direfund karenanya dan bulannya belum tutup buku.
    // Tagihan yang dibatalkan dikembalikan menjadi 'belum'; yang belum pernah dibuat, dibuat bila periodenya sudah dibuka
    public function batalkan(KegiatanLibur $libur): void
    {
        if ($libur->adaUangBerpindah()) {
            throw $this->gagal('libur', 'Penandaan ini sudah mengalihkan atau merefund uang orang tua, jadi tidak bisa dibatalkan.');
        }

        $this->periksaBelumTutupBuku($libur->bulan->toImmutable(), 'libur');

        DB::transaction(function () use ($libur) {
            $bulan = $libur->bulan->toImmutable();
            $kegiatan = $libur->les_tambahan_id ? $libur->lesTambahan : $libur->ekstrakurikuler;
            $libur->delete();

            $periodeSudahDibuka = PeriodeLaporan::where('bulan', '>=', $bulan->toDateString())->exists();

            foreach ($this->pendaftaranTerdampak($kegiatan)->get() as $pendaftaran) {
                if (! $this->tagihan->perluDitagih($pendaftaran, $bulan)) {
                    continue; // mis. masih libur lewat penandaan kegiatan induk, atau siswa sudah berhenti
                }

                $tagihan = $pendaftaran->tagihan()->where('bulan_tagihan', $bulan->toDateString())->lockForUpdate()->first();

                if ($tagihan?->status === StatusTagihan::Dibatalkan) {
                    $tagihan->update(['status' => StatusTagihan::Belum]); // dibatalkan oleh penandaan ini, tanpa uang
                } elseif (! $tagihan && $periodeSudahDibuka) {
                    $this->tagihan->buatUntukBulan($pendaftaran, $bulan);
                }
            }
        });
    }

    // Isi pratinjau dan simpan: tandai, batalkan tagihan, alihkan uang, catat penyesuaian dana
    private function proses(array $data, User $oleh): array
    {
        $kegiatan = $this->kegiatan($data['kegiatan']);
        $bulan = CarbonImmutable::createFromFormat('!Y-m', $data['bulan'])->startOfMonth();
        $this->periksa($kegiatan, $bulan);

        // Dicatat lebih dulu supaya pengalihan uang di bawah melewati bulan ini (TagihanService::perluDitagih)
        $libur = KegiatanLibur::create([
            'les_tambahan_id' => $kegiatan instanceof LesTambahan ? $kegiatan->id : null,
            'ekstrakurikuler_id' => $kegiatan instanceof Ekstrakurikuler ? $kegiatan->id : null,
            'bulan' => $bulan->toDateString(),
            'alasan' => trim($data['alasan'] ?? ''),
            'dicatat_oleh' => $oleh->id,
        ]);

        $tagihan = Tagihan::query()
            ->whereIn('pendaftaran_id', $this->pendaftaranTerdampak($kegiatan)->select('id'))
            ->where('bulan_tagihan', $bulan->toDateString())
            ->where('status', '!=', StatusTagihan::Dibatalkan)
            ->with(['transaksi', 'pembayaranOnline', 'pendaftaran.siswa', 'pendaftaran.ekstrakurikuler'])
            ->lockForUpdate()
            ->get();

        $this->periksaTagihan($tagihan);

        $label = $bulan->locale('id')->translatedFormat('F Y');
        $rincian = [];

        foreach ($tagihan as $t) {
            $uang = [];
            foreach ($t->transaksi as $transaksi) {
                $uang[$transaksi->id] = ($uang[$transaksi->id] ?? 0) + (int) $transaksi->pivot->nominal;
            }

            $t->transaksi()->detach();
            $t->update(['status' => StatusTagihan::Dibatalkan]);

            $dibayar = array_sum($uang);
            $sisa = $dibayar > 0 ? $this->transaksi->alihkanKeBulanBerikutnya($uang, $t->pendaftaran, $bulan->addMonth()) : 0;

            if ($dibayar - $sisa > 0) {
                $this->catatPenyesuaian($t->pendaftaran, $dibayar - $sisa, StatusPenyesuaian::Selesai, $oleh,
                    "Kegiatan tidak berlangsung {$label}: dialihkan ke bulan berikutnya");
            }

            if ($sisa > 0) {
                $this->catatPenyesuaian($t->pendaftaran, $sisa, StatusPenyesuaian::Proses, $oleh,
                    "Kegiatan tidak berlangsung {$label}: tidak ada bulan tujuan sampai akhir tahun ajaran");
            }

            $rincian[] = [
                'siswa' => $t->pendaftaran->siswa->nama,
                'kegiatan' => $t->pendaftaran->ekstrakurikuler?->nama, // kelas paralel siswa bila ekskul
                'dibayar' => $dibayar,
                'dialihkan' => $dibayar - $sisa,
                'refund' => $sisa,
            ];
        }

        $rincian = collect($rincian)->sortBy('siswa')->values();
        $libur->update([
            'jumlah_tagihan' => $tagihan->count(),
            'nominal_dialihkan' => $rincian->sum('dialihkan'),
            'nominal_refund' => $rincian->sum('refund'),
        ]);

        return [
            'libur' => $libur,
            'hasil' => [
                'kegiatan' => $libur->nama_kegiatan,
                'bulan' => $bulan->format('Y-m'),
                'jumlah_tagihan' => $tagihan->count(),
                'dialihkan' => $libur->nominal_dialihkan,
                'refund' => $libur->nominal_refund,
                'rincian' => $rincian->all(),
            ],
        ];
    }

    private function kegiatan(string $kunci): LesTambahan|Ekstrakurikuler
    {
        [$jenis, $id] = array_pad(explode('-', $kunci, 2), 2, null);

        $kegiatan = match ($jenis) {
            'les' => LesTambahan::find($id),
            'ekskul' => Ekstrakurikuler::find($id),
            default => null,
        };

        return $kegiatan ?? throw $this->gagal('kegiatan', 'Pilih kegiatan.');
    }

    private function periksa(LesTambahan|Ekstrakurikuler $kegiatan, CarbonImmutable $bulan): void
    {
        $tahunAjaran = $kegiatan->tahunAjaran;
        $label = $bulan->locale('id')->translatedFormat('F Y');

        if ($kegiatan->biaya <= 0) {
            throw $this->gagal('kegiatan', 'Kegiatan tanpa biaya tidak punya tagihan, jadi tidak perlu ditandai.');
        }

        if (! $bulan->between($tahunAjaran->tanggal_mulai->startOfMonth(), $tahunAjaran->tanggal_selesai)
            || in_array($bulan->month, config('les_ekskul.bulan_tidak_ditagih'), true)) {
            throw $this->gagal('bulan', "{$label} bukan bulan yang ditagih untuk tahun ajaran {$tahunAjaran->nama}.");
        }

        $this->periksaBelumTutupBuku($bulan, 'bulan');

        $sudah = KegiatanLibur::where('bulan', $bulan->toDateString())
            ->when($kegiatan instanceof LesTambahan,
                fn (Builder $q) => $q->where('les_tambahan_id', $kegiatan->id),
                fn (Builder $q) => $q->whereIn('ekstrakurikuler_id', array_filter([$kegiatan->id, $kegiatan->parent_ekstrakurikuler_id])))
            ->first();

        if ($sudah) {
            throw $this->gagal('kegiatan', $sudah->ekstrakurikuler_id && $sudah->ekstrakurikuler_id !== $kegiatan->id
                ? "{$label} sudah ditandai untuk seluruh kelas {$sudah->nama_kegiatan}."
                : "{$sudah->nama_kegiatan} sudah ditandai tidak berlangsung di {$label}.");
        }
    }

    // Uang yang sedang dalam proses belum pasti; samakan dengan aturan berhenti/pindah (#29)
    private function periksaTagihan(Collection $tagihan): void
    {
        foreach ($tagihan as $t) {
            $siswa = $t->pendaftaran->siswa->nama;

            if ($t->pembayaranOnline->contains(fn ($order) => $order->status === StatusPembayaranOnline::Menunggu)) {
                throw $this->gagal('bulan', "Tagihan {$siswa} bulan ini sedang dalam pembayaran online yang belum selesai. Tunggu sampai lunas atau kedaluwarsa.");
            }

            if ($t->transaksi->contains(fn ($tr) => $tr->status === StatusTransaksi::Pending)) {
                throw $this->gagal('bulan', "Tagihan {$siswa} bulan ini dibayar dengan transfer yang belum diverifikasi TU. Verifikasi atau tolak transfer itu dulu.");
            }

            if ($t->transaksi->contains(fn ($tr) => $tr->pivot->diakui_periode_id !== null)) {
                throw $this->gagal('bulan', "Pembayaran tagihan {$siswa} bulan ini sudah masuk tutup buku.");
            }
        }
    }

    private function periksaBelumTutupBuku(CarbonImmutable $bulan, string $field): void
    {
        $terkunci = $this->bulanTerakhirTutupBuku();

        if ($terkunci && $bulan->lte($terkunci)) {
            throw $this->gagal($field, 'Bulan '.$bulan->locale('id')->translatedFormat('F Y').' sudah tutup buku.');
        }
    }

    // Bulan periode terakhir yang sudah tutup buku; bulan ini dan sebelumnya tidak boleh diubah
    private function bulanTerakhirTutupBuku(): ?CarbonImmutable
    {
        return PeriodeLaporan::where('status', StatusPeriodeLaporan::Ditutup)->latest('bulan')->first()?->bulan->toImmutable();
    }

    // Pendaftaran pada kegiatan ini; ekskul induk mencakup seluruh kelas paralelnya
    private function pendaftaranTerdampak(LesTambahan|Ekstrakurikuler $kegiatan): Builder
    {
        if ($kegiatan instanceof LesTambahan) {
            return Pendaftaran::where('les_tambahan_id', $kegiatan->id);
        }

        $ids = $kegiatan->adalahKelasParalel() ? [$kegiatan->id] : $kegiatan->kelasParalel()->pluck('id')->push($kegiatan->id)->all();

        return Pendaftaran::whereIn('ekstrakurikuler_id', $ids);
    }

    private function periodeBerjalan(): PeriodeLaporan
    {
        return PeriodeLaporan::mencakup(today())->first()
            ?? PeriodeLaporan::latest('bulan')->first()
            ?? throw $this->gagal('bulan', 'Belum ada periode laporan. Buka periode laporan dulu.');
    }

    // Dialihkan = selesai (uang tetap di sekolah); refund = proses sampai TU menandai sudah dikembalikan di halaman Refund
    private function catatPenyesuaian(Pendaftaran $pendaftaran, int $nominal, StatusPenyesuaian $status, User $oleh, string $keterangan): void
    {
        $periode = $this->periodeBerjalan();

        PenyesuaianDana::create([
            'pendaftaran_id' => $pendaftaran->id,
            'jenis_penyesuaian' => JenisPenyesuaian::KegiatanTidakBerlangsung,
            'periode_asal_id' => $periode->id,
            'periode_tujuan_id' => $status === StatusPenyesuaian::Selesai ? $periode->id : null, // kosong untuk refund
            'nominal' => $nominal,
            'status' => $status,
            'tanggal_proses' => today(),
            'diproses_oleh' => $oleh->id,
            'keterangan' => $keterangan,
        ]);
    }

    private function gagal(string $field, string $pesan): ValidationException
    {
        return ValidationException::withMessages([$field => $pesan]);
    }
}
