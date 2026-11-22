<?php

namespace App\Domains\LesEkskul\Models;

use App\Domains\LesEkskul\Enums\StatusKonfirmasiTransfer;
use App\Models\Siswa;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

// Klaim transfer dari orang tua; bukan uang sampai TU mencatatnya menjadi transaksi (FR-15b, Ringkasan v1.11 #41)
class KonfirmasiTransfer extends Model
{
    protected $table = 'konfirmasi_transfer';

    protected $fillable = [
        'siswa_id',
        'diajukan_oleh',
        'nominal',
        'tanggal_transfer',
        'nama_pengirim',
        'status',
        'transaksi_id',
        'alasan_ditolak',
        'diproses_oleh',
        'diproses_at',
    ];

    protected $attributes = [
        'status' => 'menunggu',
    ];

    protected function casts(): array
    {
        return [
            'nominal' => 'integer',
            'tanggal_transfer' => 'date',
            'status' => StatusKonfirmasiTransfer::class,
            'diproses_at' => 'datetime',
        ];
    }

    public function siswa(): BelongsTo
    {
        return $this->belongsTo(Siswa::class);
    }

    public function pengaju(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diajukan_oleh');
    }

    public function transaksi(): BelongsTo
    {
        return $this->belongsTo(Transaksi::class);
    }

    public function tagihan(): BelongsToMany
    {
        return $this->belongsToMany(Tagihan::class, 'konfirmasi_transfer_tagihan');
    }

    public function scopeMenunggu(Builder $query): void
    {
        $query->where('status', StatusKonfirmasiTransfer::Menunggu);
    }
}
