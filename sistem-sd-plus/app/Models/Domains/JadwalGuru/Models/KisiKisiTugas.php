<?php

namespace App\Domains\JadwalGuru\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\TahunAjaran;
use App\Models\Guru;
use App\Models\JenisTugas;
use App\Models\Kelas;
use App\Models\MataPelajaran;

class KisiKisiTugas extends Model
{
    use HasFactory;

    protected $table = 'kisi_kisi_tugas';
    public $timestamps = false;

    protected $fillable = [
        'tahun_ajaran_id',
        'guru_id',
        'jenis_tugas_id',
        'kelas_id',
        'mapel_id',
        'jumlah_jam',
    ];

    public function tahunAjaran(): BelongsTo
    {
        return $this->belongsTo(TahunAjaran::class);
    }

    public function guru(): BelongsTo
    {
        return $this->belongsTo(Guru::class, 'guru_id');
    }

    public function jenisTugas(): BelongsTo
    {
        return $this->belongsTo(JenisTugas::class, 'jenis_tugas_id');
    }

    public function kelas(): BelongsTo
    {
        return $this->belongsTo(Kelas::class, 'kelas_id');
    }

    public function mataPelajaran(): BelongsTo
    {
        return $this->belongsTo(MataPelajaran::class, 'mapel_id');
    }

    public function jadwalMengajar(): HasMany
    {
        return $this->hasMany(JadwalMengajar::class, 'kisi_kisi_tugas_id');
    }
    
    // Menghitung Sisa Alokasi secara langsung di Memory
    public function getSisaJamAttribute()
    {
        $jamTerpakai = $this->jadwalMengajar()->count();
        return max(0, $this->jumlah_jam - $jamTerpakai);
    }
}