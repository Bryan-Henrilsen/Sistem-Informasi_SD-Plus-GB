<?php

namespace App\Domains\LesEkskul\Models;

use App\Domains\LesEkskul\Enums\PeranHonor;
use App\Models\User;
use Illuminate\Support\Carbon;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PenerimaPeranHonor extends Model
{
    protected $table = 'penerima_peran_honor';

    protected $fillable = [
        'peran',
        'user_id',
        'berlaku_sejak',
        'diatur_oleh',
    ];

    protected function casts(): array
    {
        return [
            'peran' => PeranHonor::class,
            'berlaku_sejak' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function diaturOlehUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diatur_oleh');
    }

    // Siapa pemegang peran pengelola pada tanggal tertentu; null bila belum diatur kepala sekolah
    public static function pemegang(PeranHonor $peran, DateTimeInterface|string $tanggal): ?User
    {
        return static::where('peran', $peran)
            ->where('berlaku_sejak', '<=', Carbon::parse($tanggal)->toDateString())
            ->latest('berlaku_sejak')
            ->first()
            ?->user;
    }
}
