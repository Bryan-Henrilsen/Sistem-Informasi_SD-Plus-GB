<?php

namespace App\Models;

use App\Domains\LesEkskul\Models\LesTambahan;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Kelas extends Model
{
    use SoftDeletes;

    protected $table = 'kelas';

    protected $fillable = ['tahun_ajaran_id', 'jenjang', 'nama_kelas', 'nama_pelindung', 'wali_kelas_id'];

    public function tahunAjaran(): BelongsTo
    {
        return $this->belongsTo(TahunAjaran::class);
    }

    public function waliKelas(): BelongsTo
    {
        return $this->belongsTo(Guru::class, 'wali_kelas_id');
    }

    public function siswaKelas(): HasMany
    {
        return $this->hasMany(SiswaKelas::class);
    }

    public function siswa(): BelongsToMany
    {
        return $this->belongsToMany(Siswa::class, 'siswa_kelas')->withTimestamps();
    }

    // Les tambahan kelas ini (paling banyak satu, kolom les_tambahan.kelas_id unik; modul Les & Ekskul #43)
    public function lesTambahan(): HasOne
    {
        return $this->hasOne(LesTambahan::class);
    }

    public function namaLengkap(): Attribute
    {
        return Attribute::get(fn () => trim("{$this->nama_kelas} {$this->nama_pelindung}"));
    }
}
