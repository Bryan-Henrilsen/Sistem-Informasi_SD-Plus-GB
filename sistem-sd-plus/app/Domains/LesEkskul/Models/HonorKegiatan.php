<?php

namespace App\Domains\LesEkskul\Models;

use App\Domains\LesEkskul\Enums\PeranHonor;
use App\Domains\LesEkskul\Enums\TipeKegiatan;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HonorKegiatan extends Model
{
    protected $table = 'honor_kegiatan';

    protected $fillable = [
        'periode_laporan_id',
        'les_tambahan_id',
        'ekstrakurikuler_id',
        'total_pendapatan',
        'dasar_honor',
        'snapshot_persen',
    ];

    protected function casts(): array
    {
        return [
            'total_pendapatan' => 'integer',
            'dasar_honor' => 'integer',
            'snapshot_persen' => 'array',
        ];
    }

    public function periodeLaporan(): BelongsTo
    {
        return $this->belongsTo(PeriodeLaporan::class);
    }

    public function lesTambahan(): BelongsTo
    {
        return $this->belongsTo(LesTambahan::class);
    }

    // Selalu kegiatan induk; kelas paralel sudah digabung (FR-16b)
    public function ekstrakurikuler(): BelongsTo
    {
        return $this->belongsTo(Ekstrakurikuler::class);
    }

    public function detail(): HasMany
    {
        return $this->hasMany(HonorDetail::class);
    }

    // Tepat satu FK kegiatan yang terisi (dijaga CHECK), jadi cukup cek salah satunya
    public function tipe(): TipeKegiatan
    {
        return $this->les_tambahan_id !== null ? TipeKegiatan::LesTambahan : TipeKegiatan::Ekstrakurikuler;
    }

    // Persen peran dari snapshot saat dihitung, bukan dari skema_honor terbaru; null bila peran itu tidak ada di skema
    public function persen(PeranHonor $peran): ?string
    {
        return $this->snapshot_persen[$peran->value] ?? null;
    }
}
