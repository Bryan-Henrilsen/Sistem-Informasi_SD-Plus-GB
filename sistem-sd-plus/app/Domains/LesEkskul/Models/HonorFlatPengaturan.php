<?php

namespace App\Domains\LesEkskul\Models;

use App\Models\TahunAjaran;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class HonorFlatPengaturan extends Model
{
    protected $table = 'honor_flat_pengaturan';

    protected $fillable = [
        'honor_flat_jenis_id',
        'tahun_ajaran_id',
        'berlaku_sejak',
        'nominal_per_guru',
        'diatur_oleh',
    ];

    protected function casts(): array
    {
        return [
            'berlaku_sejak' => 'date',
            'nominal_per_guru' => 'integer',
        ];
    }

    public function jenis(): BelongsTo
    {
        return $this->belongsTo(HonorFlatJenis::class, 'honor_flat_jenis_id');
    }

    public function tahunAjaran(): BelongsTo
    {
        return $this->belongsTo(TahunAjaran::class);
    }

    public function diaturOlehUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diatur_oleh');
    }

    // Guru/Staf penerima; honor tiap orang = nominal_per_guru x pivot->jumlah (FR-25b)
    public function penerima(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'honor_flat_penerima')
            ->withPivot('jumlah')
            ->withTimestamps();
    }
}
