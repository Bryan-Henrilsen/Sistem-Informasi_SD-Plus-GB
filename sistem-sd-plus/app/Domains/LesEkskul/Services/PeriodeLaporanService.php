<?php

namespace App\Domains\LesEkskul\Services;

use App\Domains\LesEkskul\Models\PeriodeLaporan;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PeriodeLaporanService
{
    public function __construct(private TagihanService $tagihanService) {}

    // Periode berikutnya mulai sehari setelah periode berakhir; tanpa periode sebelumnya, mulai tanggal 23 bulan lalu
    public function usulanBerikutnya(): array
    {
        $terakhir = PeriodeLaporan::latest('bulan')->first();
        $bulan = $terakhir ? $terakhir->bulan->addMonth() : today()->startOfMonth();

        return [
            'bulan' => $bulan,
            'tanggal_mulai' => $terakhir ? $terakhir->tanggal_selesai->addDay() : $bulan->subMonth()->day(23),
            'tanggal_selesai' => $bulan->day(22),
        ];
    }

    // TU/Bendahara hanya memilih tanggal selesai; bulan dan tanggal mulai mengikuti periode sebelumnya.
    // Hasilnya [periode baru, jumlah tagihan yang dibuat]
    public function buka(CarbonInterface|string $tanggalSelesai): array
    {
        return DB::transaction(function () use ($tanggalSelesai) {
            $usulan = $this->usulanBerikutnya();
            $selesai = Carbon::parse($tanggalSelesai)->toImmutable();

            if (! $selesai->isSameMonth($usulan['bulan'])) {
                throw ValidationException::withMessages([
                    'tanggal_selesai' => 'Tanggal selesai harus di bulan '.$usulan['bulan']->locale('id')->translatedFormat('F Y').'.',
                ]);
            }

            if ($selesai->lte($usulan['tanggal_mulai'])) {
                throw ValidationException::withMessages(['tanggal_selesai' => 'Tanggal selesai harus setelah tanggal mulai.']);
            }

            $periode = PeriodeLaporan::create([
                'bulan' => $usulan['bulan']->toDateString(),
                'tanggal_mulai' => $usulan['tanggal_mulai']->toDateString(),
                'tanggal_selesai' => $selesai->toDateString(),
            ]);

            return [$periode, $this->tagihanService->buatUntukPeriode($periode)];
        });
    }
}
