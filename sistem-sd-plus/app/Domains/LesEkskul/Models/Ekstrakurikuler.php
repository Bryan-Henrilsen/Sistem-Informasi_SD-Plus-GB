<?php

namespace App\Domains\LesEkskul\Models;

use App\Domains\LesEkskul\Enums\KategoriPendamping;
use App\Models\TahunAjaran;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ekstrakurikuler extends Model
{
    protected $table = 'ekstrakurikuler';

    protected $fillable = [
        'tahun_ajaran_id',
        'parent_ekstrakurikuler_id',
        'pendamping_user_id',
        'nama',
        'biaya',
        'kuota',
        'kategori_pendamping',
        'wajib_otomatis',
        'jenjang_min',
        'jenjang_max',
        'honor_flat',
    ];

    protected $attributes = [
        'wajib_otomatis' => false,
        'honor_flat' => false,
    ];

    protected function casts(): array
    {
        return [
            'kategori_pendamping' => KategoriPendamping::class,
            'wajib_otomatis' => 'boolean',
            'honor_flat' => 'boolean',
        ];
    }

    public function tahunAjaran(): BelongsTo
    {
        return $this->belongsTo(TahunAjaran::class);
    }

    public function induk(): BelongsTo
    {
        return $this->belongsTo(Ekstrakurikuler::class, 'parent_ekstrakurikuler_id');
    }

    public function kelasParalel(): HasMany
    {
        return $this->hasMany(Ekstrakurikuler::class, 'parent_ekstrakurikuler_id');
    }

    public function pendamping(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pendamping_user_id');
    }

    public function jadwal(): HasMany
    {
        return $this->hasMany(JadwalEkskul::class);
    }

    // hanya kegiatan induk yang tampil di form orang tua (FR-26)
    public function scopeKegiatanInduk(Builder $query): void
    {
        $query->whereNull('parent_ekstrakurikuler_id');
    }

    // Kegiatan yang boleh didaftar siswa jenjang tertentu; jenjang kosong = semua jenjang (FR-2)
    public function scopeUntukJenjang(Builder $query, int $jenjang): void
    {
        $query->where(function (Builder $q) use ($jenjang) {
            $q->whereNull('jenjang_min')
                ->orWhere(fn (Builder $q) => $q->where('jenjang_min', '<=', $jenjang)
                    ->where('jenjang_max', '>=', $jenjang));
        });
    }

    public function adalahKelasParalel(): bool
    {
        return $this->parent_ekstrakurikuler_id !== null;
    }

    public function pengajar(): BelongsToMany
    {
        return $this->belongsToMany(PengajarEkskul::class, 'ekskul_pengajar')->withTimestamps();
    }

    public function pendaftaran(): HasMany
    {
        return $this->hasMany(Pendaftaran::class);
    }
}
