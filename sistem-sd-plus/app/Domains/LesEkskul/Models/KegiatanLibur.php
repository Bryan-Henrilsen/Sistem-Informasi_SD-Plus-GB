<?php

namespace App\Domains\LesEkskul\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Kegiatan tidak berlangsung sebulan penuh (FR-23, Ringkasan v1.11 #48)
class KegiatanLibur extends Model
{
    protected $table = 'kegiatan_libur';

    protected $fillable = [
        'les_tambahan_id',
        'ekstrakurikuler_id',
        'bulan',
        'alasan',
        'jumlah_tagihan',
        'nominal_dialihkan',
        'nominal_refund',
        'dicatat_oleh',
    ];

    protected function casts(): array
    {
        return [
            'bulan' => 'date',
            'jumlah_tagihan' => 'integer',
            'nominal_dialihkan' => 'integer',
            'nominal_refund' => 'integer',
        ];
    }

    public function lesTambahan(): BelongsTo
    {
        return $this->belongsTo(LesTambahan::class);
    }

    public function ekstrakurikuler(): BelongsTo
    {
        return $this->belongsTo(Ekstrakurikuler::class);
    }

    public function dicatatOlehUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dicatat_oleh');
    }

    // Boleh dibatalkan hanya bila tidak ada uang yang dialihkan/direfund karenanya (cek tutup buku di Service)
    public function adaUangBerpindah(): bool
    {
        return $this->nominal_dialihkan > 0 || $this->nominal_refund > 0;
    }

    // "Les Tambahan 2A Gregory", "Basket" (induk, termasuk kelas paralelnya), atau "English Club Flyer" (satu kelas paralel)
    public function namaKegiatan(): Attribute
    {
        return Attribute::get(fn () => $this->les_tambahan_id
            ? 'Les Tambahan '.$this->lesTambahan->kelas->nama_lengkap
            : $this->ekstrakurikuler->nama);
    }
}
