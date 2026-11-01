<?php

namespace App\Domains\LesEkskul\Models;

use App\Models\Guru;
use App\Models\Kelas;
use App\Models\TahunAjaran;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LesTambahan extends Model
{
    protected $table = 'les_tambahan';

    protected $fillable = ['kelas_id', 'biaya'];

    protected static function booted(): void
    {
        // tahun_ajaran_id selalu disalin dari kelas, tidak pernah diisi manual
        static::saving(function (LesTambahan $les) {
            $les->tahun_ajaran_id = Kelas::findOrFail($les->kelas_id)->tahun_ajaran_id;
        });
    }

    public function kelas(): BelongsTo
    {
        return $this->belongsTo(Kelas::class);
    }

    public function tahunAjaran(): BelongsTo
    {
        return $this->belongsTo(TahunAjaran::class);
    }

    // Pengajar les = wali kelas dari kelas terkait (tidak disimpan terpisah)
    public function pengajar(): ?Guru
    {
        return $this->kelas->waliKelas;
    }

    public function pendaftaran(): HasMany
    {
        return $this->hasMany(Pendaftaran::class);
    }
}
