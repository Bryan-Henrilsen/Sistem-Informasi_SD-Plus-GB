<?php

namespace App\Domains\LesEkskul\Models;

use App\Domains\LesEkskul\Enums\StatusPeriodeLaporan;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class PeriodeLaporan extends Model
{
    protected $table = 'periode_laporan';

    protected $fillable = [
        'bulan',
        'tanggal_mulai',
        'tanggal_selesai',
        'status',
        'ditutup_oleh',
        'ditutup_at',
        'honor_diketahui_oleh',
        'honor_diketahui_at',
    ];

    protected $attributes = [
        'status' => 'terbuka',
    ];

    protected function casts(): array
    {
        return [
            'bulan' => 'date',
            'tanggal_mulai' => 'date',
            'tanggal_selesai' => 'date',
            'status' => StatusPeriodeLaporan::class,
            'ditutup_at' => 'datetime',
            'honor_diketahui_at' => 'datetime',
        ];
    }

    // Bendahara yang menutup buku (FR-14)
    public function ditutupOlehUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ditutup_oleh');
    }

    // Kepala Sekolah yang menandai honor "Sudah Diketahui" (FR-20)
    public function honorDiketahuiOlehUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'honor_diketahui_oleh');
    }

    public function laporanHarian(): HasMany
    {
        return $this->hasMany(LaporanHarian::class);
    }

    // Periode yang memuat tanggal tertentu; periode tidak beririsan, jadi hasilnya paling banyak satu
    public function scopeMencakup(Builder $query, DateTimeInterface|string $tanggal): void
    {
        $tanggal = Carbon::parse($tanggal)->toDateString();

        $query->where('tanggal_mulai', '<=', $tanggal)
            ->where('tanggal_selesai', '>=', $tanggal);
    }

    public function sudahDitutup(): bool
    {
        return $this->status === StatusPeriodeLaporan::Ditutup;
    }
}
