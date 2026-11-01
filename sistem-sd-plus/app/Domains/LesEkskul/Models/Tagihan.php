<?php

namespace App\Domains\LesEkskul\Models;

use App\Domains\LesEkskul\Enums\StatusTagihan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Tagihan extends Model
{
    protected $table = 'tagihan';

    protected $fillable = [
        'pendaftaran_id',
        'bulan_tagihan',
        'nominal',
        'diskon',
        'status',
    ];

    protected $attributes = [
        'diskon' => 0,
        'status' => 'belum',
    ];

    protected function casts(): array
    {
        return [
            'bulan_tagihan' => 'date',
            'nominal' => 'integer',
            'diskon' => 'integer',
            'status' => StatusTagihan::class,
        ];
    }

    public function pendaftaran(): BelongsTo
    {
        return $this->belongsTo(Pendaftaran::class);
    }

    // Transaksi yang melunasi tagihan ini, lewat pivot alokasi_transaksi
    public function transaksi(): BelongsToMany
    {
        return $this->belongsToMany(Transaksi::class, 'alokasi_transaksi')
            ->withPivot('nominal', 'diakui_periode_id')
            ->withTimestamps();
    }

    // Yang wajib dibayar = biaya penuh dikurangi diskon (FR-9)
    public function harusDibayar(): Attribute
    {
        return Attribute::get(fn () => $this->nominal - $this->diskon);
    }

    public function totalAlokasi(): int
    {
        return (int) $this->transaksi()->sum('alokasi_transaksi.nominal');
    }

    // Sisa untuk pembayaran sebagian (FR-21a)
    public function sisa(): int
    {
        return $this->harus_dibayar - $this->totalAlokasi();
    }

    public function scopeBelumLunas(Builder $query): void
    {
        $query->where('status', StatusTagihan::Belum);
    }

    // Tagihan bulan yang sudah lewat dan belum lunas
    public function scopeTunggakan(Builder $query): void
    {
        $query->belumLunas()
            ->where('bulan_tagihan', '<', today()->startOfMonth()->toDateString());
    }
}
