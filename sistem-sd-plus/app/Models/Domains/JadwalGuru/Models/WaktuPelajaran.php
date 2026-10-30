<?php

namespace App\Domains\JadwalGuru\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\TahunAjaran;

class WaktuPelajaran extends Model
{
    use HasFactory;

    protected $table = 'waktu_pelajaran';
    public $timestamps = false; // Sesuai migration yang tidak memiliki timestamps

    protected $fillable = [
        'tahun_ajaran_id',
        'urutan',
        'jam_ke',
        'nama',
        'jam_mulai',
        'jam_selesai',
        'jenis_kegiatan',
        'jenjang_min',
        'jenjang_max'
    ];

    public function tahunAjaran(): BelongsTo
    {
        return $this->belongsTo(TahunAjaran::class);
    }

    public function hariBerlaku(): HasMany
    {
        return $this->hasMany(WaktuPelajaranHari::class, 'waktu_pelajaran_id');
    }

    public function jadwalMengajar(): HasMany
    {
        return $this->hasMany(JadwalMengajar::class, 'waktu_pelajaran_id');
    }
}