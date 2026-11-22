<?php

namespace App\Domains\LesEkskul\Models;

use App\Domains\LesEkskul\Enums\StatusPendaftaran;
use App\Models\Siswa;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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

    // Pendaftaran pada kegiatan tahun ajaran tertentu; pendaftaran tahun lalu dianggap selesai tanpa mengubah datanya (#45)
    public function scopeTahunAjaran(Builder $query, int $tahunAjaranId): void
    {
        $query->where(fn (Builder $q) => $q
            ->whereHas('lesTambahan', fn (Builder $q) => $q->where('tahun_ajaran_id', $tahunAjaranId))
            ->orWhereHas('ekstrakurikuler', fn (Builder $q) => $q->where('tahun_ajaran_id', $tahunAjaranId)));
    }

    public function kegiatan(): LesTambahan|Ekstrakurikuler
    {
        return $this->adalahLesTambahan() ? $this->lesTambahan : $this->ekstrakurikuler;
    }

    // Jenjang kelas siswa pada tahun ajaran kegiatan ini; null bila siswa belum punya kelas di tahun itu
    public function jenjang(): ?int
    {
        if ($this->adalahLesTambahan()) {
            return $this->lesTambahan->kelas->jenjang; // les tambahan selalu milik satu kelas
        }

        return $this->siswa->riwayatKelas()
            ->where('tahun_ajaran_id', $this->ekstrakurikuler->tahun_ajaran_id)
            ->first()?->kelas->jenjang;
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
