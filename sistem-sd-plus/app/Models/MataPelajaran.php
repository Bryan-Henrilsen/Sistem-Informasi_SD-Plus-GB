<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class MataPelajaran extends Model
{
    protected $table = 'mata_pelajaran';

    protected $fillable = ['kode', 'nama'];

    public function guru(): BelongsToMany
    {
        return $this->belongsToMany(Guru::class, 'guru_mata_pelajaran', 'mapel_id', 'guru_id');
    }
}
