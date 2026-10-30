<?php

namespace App\Domains\JadwalGuru\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WaktuPelajaranHari extends Model
{
    use HasFactory;

    protected $table = 'waktu_pelajaran_hari';
    public $timestamps = false;
    
    // Karena kita memakai Composite Primary Key
    public $incrementing = false; 

    protected $fillable = [
        'waktu_pelajaran_id',
        'hari',
    ];

    public function waktuPelajaran(): BelongsTo
    {
        return $this->belongsTo(WaktuPelajaran::class, 'waktu_pelajaran_id');
    }
}