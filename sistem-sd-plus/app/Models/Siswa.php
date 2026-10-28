<?php

namespace App\Models;

use App\Enums\StatusSekolah;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Siswa extends Model
{
    use SoftDeletes;

    protected $table = 'siswa';

    protected $fillable = [
        'anak_guru_id', 'nis', 'nisn', 'tanggal_lahir', 'jenis_kelamin', 'nama', 'status_sekolah',
    ];

    protected $attributes = [
        'status_sekolah' => 'aktif',
    ];

    protected function casts(): array
    {
        return [
            'tanggal_lahir' => 'date',
            'status_sekolah' => StatusSekolah::class,
        ];
    }

    public function anakGuru(): BelongsTo
    {
        return $this->belongsTo(Guru::class, 'anak_guru_id');
    }

    public function orangTua(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'siswa_orang_tua')->withTimestamps();
    }

    public function riwayatKelas(): HasMany
    {
        return $this->hasMany(SiswaKelas::class);
    }

    public function kelasTahunAktif(): HasOne
    {
        return $this->hasOne(SiswaKelas::class)
            ->whereHas('tahunAjaran', fn ($q) => $q->where('status_aktif', true));
    }

    // Diskon anak guru 50%, hanya jika gurunya masih aktif
    public function berhakDiskonAnakGuru(): bool
    {
        return $this->anakGuru?->status_aktif === true;
    }
}
