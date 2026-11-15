<?php

namespace App\Domains\LesEkskul\Models;

use App\Domains\LesEkskul\Enums\KategoriKas;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Catatan kas manual untuk Laporan Keuangan Kas (Ringkasan v1.11 #35)
class KasManual extends Model
{
    protected $table = 'kas_manual';

    protected $fillable = ['periode_laporan_id', 'tanggal', 'kategori', 'uraian', 'nominal', 'dicatat_oleh'];

    protected function casts(): array
    {
        return [
            'tanggal' => 'date',
            'kategori' => KategoriKas::class,
            'nominal' => 'integer',
        ];
    }

    public function periodeLaporan(): BelongsTo
    {
        return $this->belongsTo(PeriodeLaporan::class);
    }

    public function dicatatOlehUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dicatat_oleh');
    }

    // Nilai bertanda: positif bila uang masuk, negatif bila keluar
    public function nilai(): int
    {
        return $this->kategori->masuk() ? $this->nominal : -$this->nominal;
    }
}
