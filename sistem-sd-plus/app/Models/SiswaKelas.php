<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiswaKelas extends Model
{
    protected $table = 'siswa_kelas';

    protected $fillable = ['siswa_id', 'kelas_id'];

    protected static function booted(): void
    {
        // tahun_ajaran_id selalu disalin dari kelas, tidak pernah diisi manual
        static::saving(function (SiswaKelas $siswaKelas) {
            $siswaKelas->tahun_ajaran_id = Kelas::findOrFail($siswaKelas->kelas_id)->tahun_ajaran_id;
        });
    }

    public function siswa(): BelongsTo
    {
        return $this->belongsTo(Siswa::class);
    }

    public function kelas(): BelongsTo
    {
        return $this->belongsTo(Kelas::class);
    }

    public function tahunAjaran(): BelongsTo
    {
        return $this->belongsTo(TahunAjaran::class);
    }
}
