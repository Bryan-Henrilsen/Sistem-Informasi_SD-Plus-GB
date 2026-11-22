<?php

namespace App\Domains\LesEkskul\Models;

use App\Domains\LesEkskul\Enums\JenisPenyesuaian;
use App\Domains\LesEkskul\Enums\StatusPenyesuaian;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PenyesuaianDana extends Model
{
    protected $table = 'penyesuaian_dana';

    protected $fillable = [
        'pendaftaran_id',
        'transaksi_id',
        'jenis_penyesuaian',
        'periode_asal_id',
        'periode_tujuan_id',
        'nominal',
        'status',
        'tanggal_proses',
        'diproses_oleh',
        'keterangan',
    ];

    protected function casts(): array
    {
        return [
            'jenis_penyesuaian' => JenisPenyesuaian::class,
            'nominal' => 'integer',
            'status' => StatusPenyesuaian::class,
            'tanggal_proses' => 'date',
        ];
    }

    public function pendaftaran(): BelongsTo
    {
        return $this->belongsTo(Pendaftaran::class);
    }

    public function transaksi(): BelongsTo
    {
        return $this->belongsTo(Transaksi::class);
    }

    public function periodeAsal(): BelongsTo
    {
        return $this->belongsTo(PeriodeLaporan::class, 'periode_asal_id');
    }

    // Kosong untuk refund (uang keluar, tidak berpindah ke periode lain)
    public function periodeTujuan(): BelongsTo
    {
        return $this->belongsTo(PeriodeLaporan::class, 'periode_tujuan_id');
    }

    public function diprosesOlehUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diproses_oleh');
    }

    // Jenis yang bisa berupa refund ke orang tua; untuk kegiatan tidak berlangsung (#48) hanya bila statusnya proses/berhasil,
    // karena bagian yang dialihkan ke bulan berikutnya berstatus selesai
    public static function jenisRefund(): array
    {
        return [JenisPenyesuaian::KelebihanTransfer, JenisPenyesuaian::RefundBerhenti, JenisPenyesuaian::KegiatanTidakBerlangsung];
    }

    public function adalahRefund(): bool
    {
        return in_array($this->jenis_penyesuaian, self::jenisRefund(), true) && $this->status !== StatusPenyesuaian::Selesai;
    }
}
