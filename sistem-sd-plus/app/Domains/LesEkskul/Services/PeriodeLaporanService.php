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

    // Periode berikutnya mulai sehari setelah periode berakhir; tanpa periode sebelumnya, usulan mulai tanggal 23 bulan lalu
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

    // Periode pertama: tanggal mulai boleh dipilih TU (mis. 1 Agustus); periode berikutnya selalu menyambung (#39)
    public function periodePertama(): bool
    {
        return PeriodeLaporan::doesntExist();
    }

    // TU/Bendahara memilih tanggal selesai; bulan dan tanggal mulai mengikuti periode sebelumnya, sehingga setiap tanggal
    // masuk tepat satu periode. Khusus periode pertama, tanggal mulai boleh dipilih (Ringkasan v1.11 #39).
    // Hasilnya [periode baru, jumlah tagihan yang dibuat]
    public function buka(CarbonInterface|string $tanggalSelesai, CarbonInterface|string|null $tanggalMulai = null): array
    {
        return DB::transaction(function () use ($tanggalSelesai, $tanggalMulai) {
            $usulan = $this->usulanBerikutnya();
            $selesai = Carbon::parse($tanggalSelesai)->toImmutable();
            $mulai = $this->periodePertama() && $tanggalMulai
                ? Carbon::parse($tanggalMulai)->toImmutable()->startOfDay()
                : $usulan['tanggal_mulai'];

            if (! $selesai->isSameMonth($usulan['bulan'])) {
                throw ValidationException::withMessages([
                    'tanggal_selesai' => 'Tanggal selesai harus di bulan '.$usulan['bulan']->locale('id')->translatedFormat('F Y').'.',
                ]);
            }

            // Periode pertama paling awal dimulai awal bulan sebelumnya, supaya satu periode tidak lebih dari ±2 bulan
            if ($mulai->lt($usulan['bulan']->subMonth()->startOfMonth())) {
                throw ValidationException::withMessages([
                    'tanggal_mulai' => 'Tanggal mulai paling awal '.$usulan['bulan']->subMonth()->startOfMonth()->locale('id')->translatedFormat('j F Y').'.',
                ]);
            }

            if ($selesai->lte($mulai)) {
                throw ValidationException::withMessages(['tanggal_selesai' => 'Tanggal selesai harus setelah tanggal mulai.']);
            }

            $periode = PeriodeLaporan::create([
                'bulan' => $usulan['bulan']->toDateString(),
                'tanggal_mulai' => $mulai->toDateString(),
                'tanggal_selesai' => $selesai->toDateString(),
            ]);

            return [$periode, $this->tagihanService->buatUntukPeriode($periode)];
        });
    }
}
