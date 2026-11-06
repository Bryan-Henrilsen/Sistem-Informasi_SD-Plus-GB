<?php

namespace App\Domains\LesEkskul\Services;

use App\Domains\LesEkskul\Enums\StatusPendaftaran;
use App\Domains\LesEkskul\Models\Pendaftaran;
use App\Domains\LesEkskul\Models\PeriodeLaporan;
use App\Domains\LesEkskul\Models\Tagihan;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

// Pembuatan tagihan bulanan (FR-9, A6 #17)
class TagihanService
{
    // (a) Saat periode laporan dibuka: tagih semua pendaftaran yang masih ikut di bulan periode itu
    public function buatUntukPeriode(PeriodeLaporan $periode): int
    {
        $bulan = $periode->bulan->startOfMonth();

        $kandidat = Pendaftaran::query()
            ->where(fn (Builder $q) => $q->where('status', StatusPendaftaran::Aktif)
                ->orWhere('tanggal_efektif_berhenti', '>', $bulan->toDateString()))
            ->with(['siswa.anakGuru', 'lesTambahan.tahunAjaran', 'ekstrakurikuler.tahunAjaran'])
            ->get();

            return DB::transaction(fn () => $kandidat
                ->map(fn (Pendaftaran $p) => $this->buatUntukBulan($p, $bulan))
                ->filter(fn (?Tagihan $t) => $t?->wasRecentlyCreated)
                ->count());
    }

    // (b) Pendaftaran baru: tagih dari bulan daftar sampai bulan periode terbaru yang sudah dibuka
    public function buatSetelahDaftar(Pendaftaran $pendaftaran): Collection
    {
        $periodeTerbaru = PeriodeLaporan::latest('bulan')->first();

        return $periodeTerbaru ? $this->buatSampai($pendaftaran, $periodeTerbaru->bulan): collect();
    }

    // (c) Bayar di muka: tagihan sampai bulan tertentu, mulai dari bulan daftar; yang sudah ada tidak dibuat ulang
    public function buatSampai(Pendaftaran $pendaftaran, DateTimeInterface|string $bulanAkhir): Collection
    {
        $bulan = $pendaftaran->tanggal_daftar->startOfMonth();
        $akhir = Carbon::parse($bulanAkhir)->startOfMonth();
        $hasil = collect();

        while ($bulan->lte($akhir)) {
            $tagihan = $this->buatUntukBulan($pendaftaran, $bulan);
            if ($tagihan) {
                $hasil->push($tagihan);
            }
            $bulan = $bulan->addMonth(); // tanggal dari Model immutable: hasil addMonth() wajib ditampung
        }

        return $hasil;
    }

    // Tagihan satu bulan; null bila bulan itu tidak ditagih. Aman dipanggil berulang (unique pendaftaran dan bulan)
    public function buatUntukBulan(Pendaftaran $pendaftaran, DateTimeInterface|string $bulan): ?Tagihan
    {
        $bulan = Carbon::parse($bulan)->startOfMonth();

        if (! $this->perluDitagih($pendaftaran, $bulan)) {
            return null;
        }

        $biaya = $pendaftaran->kegiatan()->biaya;

        return Tagihan::firstOrCreate(
            ['pendaftaran_id' => $pendaftaran->id, 'bulan_tagihan' => $bulan->toDateString()], ['nominal' => $biaya, 'diskon' => $this->hitungDiskon($pendaftaran, $biaya)],
        );
    }

    public function perluDitagih(Pendaftaran $pendaftaran, CarbonInterface $bulan): bool
    {
        $kegiatan = $pendaftaran->kegiatan();
        $tahunAjaran = $kegiatan->tahunAjaran;

        return $kegiatan->biaya > 0 // Olimpiade (biaya 0) tidak ditagih
            && ! in_array($bulan->month, config('les_ekskul.bulan_tidak_ditagih'), true)
            && $bulan->between($tahunAjaran->tanggal_mulai->startOfMonth(), $tahunAjaran->tanggal_selesai)
            && $pendaftaran->tanggal_daftar->lte($bulan->copy()->endOfMonth()) // sudah terdaftar di bulan itu
            && $this->masihIkut($pendaftaran, $bulan)
            && ! in_array($bulan->month, $this->bulanLiburJenjang($pendaftaran), true);
    }

    // Bulan tidak ditagih khusus jenjang siswa (config les_ekskul.bulan_tidak_ditagih_per_jenjang)
    private function bulanLiburJenjang(Pendaftaran $pendaftaran): array
    {
        $jenjang = $pendaftaran->jenjang();

        return $jenjang ? config('les_ekskul.bulan_tidak_ditagih_per_jenjang')[$jenjang] ?? [] : [];
    }

    private function masihIkut(Pendaftaran $pendaftaran, CarbonInterface $bulan): bool
    {
        if ($pendaftaran->tanggal_efektif_berhenti === null) {
            return $pendaftaran->status === StatusPendaftaran::Aktif;
        }

        return $pendaftaran->tanggal_efektif_berhenti->gt($bulan);
    }

    private function hitungDiskon(Pendaftaran $pendaftaran, int $biaya): int
    {
        return $pendaftaran->siswa->berhakDiskonAnakGuru() ? intdiv($biaya, 2) : 0;
    }
}
