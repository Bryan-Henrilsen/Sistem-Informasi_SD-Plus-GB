<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JenisTugas extends Model
{
    protected $table = 'jenis_tugas';

    protected $fillable = ['kode', 'nama'];

    public static function byKode(string $kode): self
    {
        return static::where('kode', $kode)->firstOrFail();
    }
}
