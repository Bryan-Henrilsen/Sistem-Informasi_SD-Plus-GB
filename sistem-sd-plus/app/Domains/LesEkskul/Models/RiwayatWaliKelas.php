<?php

namespace App\Domains\LesEkskul\Models;

use App\Models\Guru;
use App\Models\Kelas;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Riwayat wali kelas untuk honor les tambahan (Ringkasan v1.11 #50); dibuat saat TU memakai "Ganti wali kelas".
// Kelas tanpa riwayat memakai kelas.wali_kelas_id untuk seluruh bulan
class RiwayatWaliKelas extends Model
{
    protected $table = 'riwayat_wali_kelas';

    protected $fillable = ['kelas_id', 'guru_id', 'tanggal_mulai', 'tanggal_selesai', 'dicatat_oleh'];

    protected function casts(): array
    {
        return [
            'tanggal_mulai' => 'date',
            'tanggal_selesai' => 'date',
        ];
    }

    public function kelas(): BelongsTo
    {
        return $this->belongsTo(Kelas::class);
    }

    public function guru(): BelongsTo
    {
        return $this->belongsTo(Guru::class);
    }

    public function dicatatOlehUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dicatat_oleh');
    }
}
