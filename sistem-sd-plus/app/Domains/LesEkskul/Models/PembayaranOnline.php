<?php

namespace App\Domains\LesEkskul\Models;

use App\Domains\LesEkskul\Enums\StatusPembayaranOnline;
use App\Models\Siswa;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class PembayaranOnline extends Model
{
    protected $table = 'pembayaran_online';

    protected $fillable = [
        'order_id',
        'siswa_id',
        'dibuat_oleh',
        'nominal',
        'biaya_admin',
        'metode_gateway',
        'kode_bayar',
        'gateway_ref',
        'status',
        'kedaluwarsa_at',
        'dibayar_at',
        'transaksi_id',
        'payload_terakhir',
    ];

    protected $attributes = [
        'status' => 'menunggu',
    ];

    protected function casts(): array
    {
        return [
            'nominal' => 'integer',
            'biaya_admin' => 'integer',
            'total_bayar' => 'integer',
            'status' => StatusPembayaranOnline::class,
            'kedaluwarsa_at' => 'datetime',
            'dibayar_at' => 'datetime',
            'payload_terakhir' => 'array',
        ];
    }

    protected static function booted(): void
    {
        // total_bayar selalu dihitung, tidak pernah diisi manual; CHECK di database menjadi pengaman terakhir
        static::saving(function (PembayaranOnline $order) {
            $order->total_bayar = $order->nominal + $order->biaya_admin;
        });
    }

    public function siswa(): BelongsTo
    {
        return $this->belongsTo(Siswa::class);
    }

    // Orang tua yang membuat order
    public function dibuatOlehUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dibuat_oleh');
    }

    // Transaksi hasil order ini; terisi setelah webhook lunas (FR-30)
    public function transaksi(): BelongsTo
    {
        return $this->belongsTo(Transaksi::class);
    }

    // Tagihan yang dipilih orang tua; pivot nominal = sisa tagihan saat order dibuat
    public function tagihan(): BelongsToMany
    {
        return $this->belongsToMany(Tagihan::class, 'pembayaran_online_tagihan')
            ->withPivot('nominal')
            ->withTimestamps();
    }

    public function scopeMenunggu(Builder $query): void
    {
        $query->where('status', StatusPembayaranOnline::Menunggu);
    }

    // Order menunggu yang batas waktunya sudah lewat; untuk job penanda kedaluwarsa bila webhook tidak datang
    public function scopeLewatBatas(Builder $query): void
    {
        $query->menunggu()->where('kedaluwarsa_at', '<=', now());
    }

    public function sudahLunas(): bool
    {
        return $this->status === StatusPembayaranOnline::Lunas;
    }
}
