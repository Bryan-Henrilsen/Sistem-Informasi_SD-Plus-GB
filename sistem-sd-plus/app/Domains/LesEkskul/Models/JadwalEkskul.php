<?php

namespace App\Domains\LesEkskul\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JadwalEkskul extends Model
{
    protected $table = 'jadwal_ekskul';

    protected $fillable = [
        'ekstrakurikuler_id',
        'hari',
        'jam_mulai',
        'jam_selesai',
        'jenjang_min',
        'jenjang_max',
        'lokasi',
    ];

    public function ekstrakurikuler(): BelongsTo
    {
        return $this->belongsTo(Ekstrakurikuler::class);
    }
}
