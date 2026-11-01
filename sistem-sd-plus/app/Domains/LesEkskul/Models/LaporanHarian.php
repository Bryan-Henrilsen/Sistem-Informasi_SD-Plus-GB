<?php

namespace App\Domains\LesEkskul\Models;

use App\Domains\LesEkskul\Enums\StatusLaporanHarian;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LaporanHarian extends Model
{
    protected $table = 'laporan_harian';

    protected $fillable = [
        'periode_laporan_id',
        'tanggal',
        'status',
        'diajukan_oleh',
        'diajukan_at',
        'disetujui_oleh',
        'disetujui_at',
        'alasan_ditolak',
        'ditolak_oleh',
        'ditolak_at',
    ];

    protected $attributes = [
        'status' => 'draft',
    ];

    protected function casts(): array
    {
        return [
            'tanggal' => 'date',
            'status' => StatusLaporanHarian::class,
            'diajukan_at' => 'datetime',
            'disetujui_at' => 'datetime',
            'ditolak_at' => 'datetime',
        ];
    }

    public function periodeLaporan(): BelongsTo
    {
        return $this->belongsTo(PeriodeLaporan::class);
    }

    public function diajukanOlehUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diajukan_oleh');
    }
    public function disetujuiOlehUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disetujui_oleh');
    }
    public function ditolakOlehUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ditolak_oleh');
    }

    public function transaksi(): HasMany
    {
        return $this->hasMany(Transaksi::class);
    }

    // Laporan yang sudah disetujui tidak dibuka kembali; transaksi baru masuk laporan hari pencatatan (FR-8c)
    public function sudahDisetujui(): bool
    {
        return $this->status === StatusLaporanHarian::Disetujui;
    }

    // TU boleh menarik kembali laporan yang belum diputuskan Bendahara untuk menambah transaksi susulan
    public function bisaDitarikKembali(): bool
    {
        return $this->status === StatusLaporanHarian::Diajukan;
    }
}
