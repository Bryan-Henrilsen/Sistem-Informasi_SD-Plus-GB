<?php

namespace App\Domains\LesEkskul\Models;

use App\Domains\LesEkskul\Enums\JenisPengajar;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class PengajarEkskul extends Model
{
    protected $table = 'pengajar_ekskul';

    protected $fillable = [
        'user_id',
        'nama',
        'jenis',
        'no_hp',
        'status_aktif'
    ];

    protected $attributes = [
        'status_aktif' => true,
    ];

    protected function casts(): array
    {
        return [
            'jenis' => JenisPengajar::class,
            'status_aktif' => 'boolean',
        ];
    }

    // Akun guru/staf; null untuk pengajar eksternal
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function ekstrakurikuler(): BelongsToMany
    {
        return $this->belongsToMany(Ekstrakurikuler::class, 'ekskul_pengajar')->withTimestamps();
    }
}
