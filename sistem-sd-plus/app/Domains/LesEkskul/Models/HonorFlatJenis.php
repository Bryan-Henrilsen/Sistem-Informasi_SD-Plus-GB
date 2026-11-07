<?php

namespace App\Domains\LesEkskul\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HonorFlatJenis extends Model
{
    protected $table = 'honor_flat_jenis';

    protected $fillable = ['kode', 'nama', 'keterangan'];

    public static function byKode(string $kode): self
    {
        return static::where('kode', $kode)->firstOrFail();
    }

    public function pengaturan(): HasMany
    {
        return $this->hasMany(HonorFlatPengaturan::class);
    }
}
