<?php

namespace App\Domains\LesEkskul\Models;

use App\Domains\LesEkskul\Enums\StatusPendaftaran;
use App\Models\Siswa;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pendaftaran extends Model
{
    protected $table = 'pendaftaran';

    protected $fillable = [
        'siswa_id',
        'les_tambahan_id',
        'ekstrakurikuler_id',
        'tanggal_daftar',
        'status',
        'tanggal_efektif_berhenti',
        'alasan_berhenti',
        'pindah_ke_id',
    ];

    protected $attributes = [
        'status' => 'aktif',
    ];

    protected function casts(): array
    {
        return [
            'tanggal_daftar' => 'date',
            'tanggal_efektif_berhenti' => 'date',
            'status' => StatusPendaftaran::class,
        ];
    }

    public function siswa(): BelongsTo
    {
        return $this->belongsTo(Siswa::class);
    }

    public function lesTambahan(): BelongsTo
    {
        return $this->belongsTo(LesTambahan::class);
    }

    public function ekstrakurikuler(): BelongsTo
    {
        return $this->belongsTo(Ekstrakurikuler::class);
    }

    public function tagihan(): HasMany
    {
        return $this->hasMany(Tagihan::class);
    }

    // Riwayat penyesuaian dana per kegiatan (alih, kelebihan, refund)
    public function penyesuaianDana(): HasMany
    {
        return $this->hasMany(PenyesuaianDana::class);
    }

    // Pendaftaran baru tujuan perpindahan (FR-11)
    public function pindahKe(): BelongsTo
    {
        return $this->belongsTo(Pendaftaran::class, 'pindah_ke_id');
    }

    // Pendaftaran lama yang pindah ke pendaftaran ini
    public function pindahDari(): HasOne
    {
        return $this->hasOne(Pendaftaran::class, 'pindah_ke_id');
    }

    public function scopeAktif(Builder $query): void
    {
        $query->where('status', StatusPendaftaran::Aktif);
    }

    public function kegiatan(): LesTambahan|Ekstrakurikuler
    {
        return $this->adalahLesTambahan() ? $this->lesTambahan : $this->ekstrakurikuler;
    }

    public function adalahLesTambahan(): bool
    {
        return $this->les_tambahan_id !== null;
    }

    // Nama kegiatan untuk tampilan; tepat satu dari les/ekskul yang terisi
    public function namaKegiatan(): Attribute
    {
        return Attribute::get(fn () => $this->adalahLesTambahan()
            ? 'Les Tambahan '.$this->lesTambahan->kelas->nama_lengkap
            : $this->ekstrakurikuler->nama);
    }
}
