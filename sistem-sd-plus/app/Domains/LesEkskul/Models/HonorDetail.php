<?php

namespace App\Domains\LesEkskul\Models;

use App\Domains\LesEkskul\Enums\PeranHonor;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HonorDetail extends Model
{
    protected $table = 'honor_detail';

    protected $fillable = [
        'periode_laporan_id',
        'honor_kegiatan_id',
        'honor_flat_pengaturan_id',
        'penerima_user_id',
        'penerima_pengajar_id',
        'peran',
        'nominal',
    ];

    protected function casts(): array
    {
        return [
            'peran' => PeranHonor::class,
            'nominal' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Baris persentase selalu menyalin periode dari honor_kegiatan; baris flat diisi periode yang dihitung
        static::saving(function (HonorDetail $detail) {
            if ($detail->honor_kegiatan_id !== null) {
                $detail->periode_laporan_id = HonorKegiatan::findOrFail($detail->honor_kegiatan_id)->periode_laporan_id;
            }
        });
    }

    public function periodeLaporan(): BelongsTo
    {
        return $this->belongsTo(PeriodeLaporan::class);
    }

    public function honorKegiatan(): BelongsTo
    {
        return $this->belongsTo(HonorKegiatan::class);
    }

    public function honorFlatPengaturan(): BelongsTo
    {
        return $this->belongsTo(HonorFlatPengaturan::class);
    }

    public function penerimaUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'penerima_user_id');
    }

    // Pengajar eksternal yang tidak punya akun
    public function penerimaPengajar(): BelongsTo
    {
        return $this->belongsTo(PengajarEkskul::class, 'penerima_pengajar_id');
    }

    // Nama untuk rekap FR-19; Kas Sekolah dan Yayasan tidak punya penerima orang
    public function namaPenerima(): string
    {
        return $this->penerimaUser?->name
            ?? $this->penerimaPengajar?->nama
            ?? match ($this->peran) {
                PeranHonor::KasSekolah => 'Kas Sekolah',
                PeranHonor::Yayasan => 'Yayasan',
                default => '-',
            };
    }
}
