<?php

namespace App\Domains\LesEkskul\Models;

use App\Models\TahunAjaran;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PeriodePendaftaran extends Model
{
    protected $table = 'periode_pendaftaran';

    protected $fillable = ['tahun_ajaran_id', 'tanggal_mulai', 'tanggal_selesai'];

    protected function casts(): array
    {
        return [
            'tanggal_mulai' => 'date',
            'tanggal_selesai' => 'date',
        ];
    }

    public function tahunAjaran(): BelongsTo
    {
        return $this->belongsTo(TahunAjaran::class);
    }

    // orang tua hanya boleh mendaftar dalam periode ini; TU tidak dibatasi (FR-6)
    public function sedangBuka(): bool
    {
        return today()->between($this->tanggal_mulai, $this->tanggal_selesai);
    }
}
