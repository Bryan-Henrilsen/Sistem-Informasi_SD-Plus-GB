<?php

namespace App\Domains\JurnalGuru\Models;

use App\Models\Guru;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Satu pesan di forum privat seorang guru dengan Kepala Sekolah (PRD B.2, Ringkasan v1.11 #51).
// Pesan dari guru bila pengirim_id = akun guru pemilik thread; selain itu dari Kepala Sekolah
class ForumPesan extends Model
{
    public const UPDATED_AT = null; // pesan tidak bisa diubah

    // Batas waktu pengirim boleh menghapus pesannya (salah ketik); sesudahnya menjadi arsip tetap
    public const MENIT_BOLEH_HAPUS = 15;

    protected $table = 'forum_pesan';

    protected $fillable = ['guru_id', 'pengirim_id', 'isi', 'dibaca_at'];

    protected function casts(): array
    {
        return [
            'dibaca_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function guru(): BelongsTo
    {
        return $this->belongsTo(Guru::class);
    }

    public function pengirim(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pengirim_id');
    }

    public function bisaDihapusOleh(User $user): bool
    {
        return $this->pengirim_id === $user->id && $this->created_at->gt(now()->subMinutes(self::MENIT_BOLEH_HAPUS));
    }
}
