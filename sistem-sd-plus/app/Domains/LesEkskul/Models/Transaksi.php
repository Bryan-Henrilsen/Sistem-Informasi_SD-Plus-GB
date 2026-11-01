<?php

namespace App\Domains\LesEkskul\Models;

use App\Domains\LesEkskul\Enums\MetodeTransaksi;
use App\Domains\LesEkskul\Enums\StatusApprovalBendahara;
use App\Domains\LesEkskul\Enums\StatusTransaksi;
use App\Domains\LesEkskul\Enums\SumberInput;
use App\Models\Siswa;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Transaksi extends Model
{
    protected $table = 'transaksi';

    protected $fillable = [
        'siswa_id',
        'laporan_harian_id',
        'tanggal',
        'metode',
        'nominal',
        'status',
        'catatan_transfer',
        'sumber_input',
        'diinput_oleh',
        'diverifikasi_oleh',
        'diverifikasi_at',
        'status_approval_bendahara',
        'diapprove_oleh',
        'tanggal_approval_bendahara',
        'alasan_ditolak',
        'ditolak_oleh',
        'ditolak_at',
    ];

    protected $attributes = [
        'sumber_input' => 'tu',
    ];

    protected function casts(): array
    {
        return [
            'tanggal' => 'date',
            'metode' => MetodeTransaksi::class,
            'nominal' => 'integer',
            'status' => StatusTransaksi::class,
            'sumber_input' => SumberInput::class,
            'status_approval_bendahara' => StatusApprovalBendahara::class,
            'diverifikasi_at' => 'datetime',
            'tanggal_approval_bendahara' => 'datetime',
            'ditolak_at' => 'datetime',
        ];
    }

    public function siswa(): BelongsTo
    {
        return $this->belongsTo(Siswa::class);
    }

    // Laporan harian hari pencatatan, bukan selalu hari uang masuk
    public function laporanHarian(): BelongsTo
    {
        return $this->belongsTo(LaporanHarian::class);
    }

    public function diinputOlehUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diinput_oleh');
    }

    public function diverifikasiOlehUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diverifikasi_oleh');
    }

    public function diapproveOlehUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diapprove_oleh');
    }

    public function ditolakOlehUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ditolak_oleh');
    }

    // Tagihan yang dilunasi transaksi ini, lewat pivot alokasi_transaksi
    public function tagihan(): BelongsToMany
    {
        return $this->belongsToMany(Tagihan::class, 'alokasi_transaksi')
            ->withPivot('nominal', 'diakui_periode_id')
            ->withTimestamps();
    }

    // Dihitung, bukan kolom: uang masuk sebelum tanggal laporan tempatnya dicatat (FR-8c, Catatan v1.11)
    public function dicatatTerlambat(): bool
    {
        return $this->tanggal->lt($this->laporanHarian->tanggal); // lt = less than
    }
}
