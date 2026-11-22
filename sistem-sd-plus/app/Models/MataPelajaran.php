<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class MataPelajaran extends Model
{
    protected $table = 'mata_pelajaran';

    protected $fillable = ['kode', 'nama', 'aktif'];

    protected $attributes = [
        'aktif' => true,
    ];

    protected function casts(): array
    {
        return [
            'aktif' => 'boolean',
        ];
    }

    public function guru(): BelongsToMany
    {
        return $this->belongsToMany(Guru::class, 'guru_mata_pelajaran', 'mapel_id', 'guru_id');
    }

    // Mapel yang masih ditawarkan di pilihan baru (#47)
    public function scopeAktif(Builder $query): void
    {
        $query->where('aktif', true);
    }
}
