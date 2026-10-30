<?php

namespace App\Domains\JadwalGuru\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Guru;
use App\Models\JenisTugas;
use App\Models\Kelas;

class JadwalMengajar extends Model
{
    use HasFactory;

    protected $table = 'jadwal_mengajar';
    public $timestamps = false;

    protected $fillable = [
        'kisi_kisi_tugas_id',
        'waktu_pelajaran_id',
        'guru_id',
        'jenis_tugas_id',
        'kelas_id',
        'parent_jadwal_id',
        'hari',
    ];

    /**
     * Event Lifecycle Laravel
     * Sesuai PRD v1.9: Kolom salinan di jadwal_mengajar (guru_id, jenis_tugas_id, kelas_id) 
     * diisi otomatis lewat event saving di model.
     */
    protected static function booted(): void
    {
        static::saving(function (JadwalMengajar $jadwal) {
            if ($jadwal->kisi_kisi_tugas_id) {
                // Ambil data Kisi-Kisi beserta relasi Master Jenis Tugas
                $kisiKisi = KisiKisiTugas::with('jenisTugas')->find($jadwal->kisi_kisi_tugas_id);
                
                if ($kisiKisi) {
                    $jadwal->guru_id = $kisiKisi->guru_id;
                    $jadwal->jenis_tugas_id = $kisiKisi->jenis_tugas_id;
                    
                    $kodeTugas = $kisiKisi->jenisTugas ? $kisiKisi->jenisTugas->kode : null;
                    
                    if ($kodeTugas === 'utama') {
                        $jadwal->kelas_id = $kisiKisi->kelas_id;
                    } elseif ($kodeTugas === 'damping') {
                        // Guru damping menempel ke kelas milik parent jadwalnya
                        if ($jadwal->parent_jadwal_id) {
                            $parent = self::find($jadwal->parent_jadwal_id);
                            $jadwal->kelas_id = $parent ? $parent->kelas_id : null;
                        }
                    } else {
                        // Piket (tidak punya kelas)
                        $jadwal->kelas_id = null;
                    }
                }
            }
        });
    }

    public function kisiKisiTugas(): BelongsTo
    {
        return $this->belongsTo(KisiKisiTugas::class, 'kisi_kisi_tugas_id');
    }

    public function waktuPelajaran(): BelongsTo
    {
        return $this->belongsTo(WaktuPelajaran::class, 'waktu_pelajaran_id');
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

    public function jadwalUtama(): BelongsTo
    {
        return $this->belongsTo(JadwalMengajar::class, 'parent_jadwal_id');
    }

    public function jadwalDamping(): HasMany
    {
        return $this->hasMany(JadwalMengajar::class, 'parent_jadwal_id');
    }
}