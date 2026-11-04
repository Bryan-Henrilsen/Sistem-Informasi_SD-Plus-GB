<?php

namespace App\Domains\LesEkskul\Models;

use App\Domains\LesEkskul\Enums\JenisPengajar;
use App\Domains\LesEkskul\Enums\TipeKegiatan;
use Illuminate\Support\Carbon;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

class SkemaHonor extends Model
{
    protected $table = 'skema_honor';

    protected $fillable = [
        'tipe_kegiatan',
        'jenis_pengajar',
        'persen_pengajar',
        'persen_guru_pendamping',
        'persen_kas_sekolah',
        'persen_yayasan',
        'persen_penanggung_jawab',
        'persen_bendahara',
        'persen_admin_tu',
        'persen_admin_it',
        'berlaku_sejak',
    ];

    protected function casts(): array
    {
        return [
            'tipe_kegiatan' => TipeKegiatan::class,
            'jenis_pengajar' => JenisPengajar::class,
            'persen_pengajar' => 'decimal:2',
            'persen_guru_pendamping' => 'decimal:2',
            'persen_kas_sekolah' => 'decimal:2',
            'persen_yayasan' => 'decimal:2',
            'persen_penanggung_jawab' => 'decimal:2',
            'persen_bendahara' => 'decimal:2',
            'persen_admin_tu' => 'decimal:2',
            'persen_admin_it' => 'decimal:2',
            'berlaku_sejak' => 'date',
        ];
    }

    // Skema yang berlaku pada tanggal tertentu: baris terbaru yang berlaku_sejak-nya tidak melewati tanggal itu (FR-17)
    public static function berlakuPada(TipeKegiatan $tipe, ?JenisPengajar $jenisPengajar, DateTimeInterface|string $tanggal): ?self
    {
        return static::where('tipe_kegiatan', $tipe)
            ->where('jenis_pengajar', $jenisPengajar)
            ->where('berlaku_sejak', '<=', Carbon::parse($tanggal)->toDateString())
            ->latest('berlaku_sejak')
            ->first();
    }

    // Salinan persen untuk honor_kegiatan.snapshot_persen; kuncinya sama dengan nilai PeranHonor
    public function snapshot(): array
    {
        return [
            'pengajar' => $this->persen_pengajar,
            'guru_pendamping' => $this->persen_guru_pendamping,
            'kas_sekolah' => $this->persen_kas_sekolah,
            'yayasan' => $this->persen_yayasan,
            'penanggung_jawab' => $this->persen_penanggung_jawab,
            'bendahara' => $this->persen_bendahara,
            'admin_tu' => $this->persen_admin_tu,
            'admin_it' => $this->persen_admin_it,
        ];
    }
}
