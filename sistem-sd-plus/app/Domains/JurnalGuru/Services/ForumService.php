<?php

namespace App\Domains\JurnalGuru\Services;

use App\Domains\JurnalGuru\Models\ForumPesan;
use App\Models\Guru;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Forum privat guru ↔ Kepala Sekolah (PRD B.2 FR-2–FR-4, Ringkasan v1.11 #51). Satu percakapan berkelanjutan per guru;
// guru hanya membuka percakapannya sendiri, Kepala Sekolah membuka semua dan boleh memulai lebih dulu
class ForumService
{
    public const PANJANG_MAKS = 2000;

    // Data guru aktif milik akun ber-role guru; null = bukan guru (mis. staf TU), tidak punya forum
    public function guruMilik(User $user): ?Guru
    {
        return $user->hasRole('guru') ? Guru::where('user_id', $user->id)->where('status_aktif', true)->first() : null;
    }

    public function bolehBuka(User $user, Guru $guru): bool
    {
        return $user->hasRole('kepala_sekolah') || ($user->hasRole('guru') && $guru->user_id === $user->id);
    }

    // Daftar percakapan untuk Kepala Sekolah: guru aktif (dan guru nonaktif yang punya pesan), yang belum dibaca di atas,
    // lalu yang pesannya paling baru, lalu nama
    public function daftarThread(): Collection
    {
        $terakhir = ForumPesan::query()
            ->select('guru_id', DB::raw('MAX(created_at) as terakhir'), DB::raw('COUNT(*) as jumlah'))
            ->groupBy('guru_id')
            ->get()
            ->keyBy('guru_id');
        $belumDibaca = $this->pesanGuruBelumDibaca()->select('forum_pesan.guru_id', DB::raw('COUNT(*) as jumlah'))
            ->groupBy('forum_pesan.guru_id')->pluck('jumlah', 'guru_id');
        $cuplikan = ForumPesan::whereIn('id', ForumPesan::select(DB::raw('MAX(id)'))->groupBy('guru_id'))->get()->keyBy('guru_id');

        return Guru::query()
            ->where(fn ($q) => $q->where('status_aktif', true)->orWhereIn('id', $terakhir->keys()))
            ->with('user:id,name')
            ->get()
            ->map(fn (Guru $g) => [
                'guru_id' => $g->id,
                'nama' => $g->user?->name ?? $g->kode_guru,
                'aktif' => $g->status_aktif,
                'jumlah_pesan' => (int) ($terakhir[$g->id]->jumlah ?? 0),
                'belum_dibaca' => (int) ($belumDibaca[$g->id] ?? 0),
                'terakhir' => isset($cuplikan[$g->id]) ? $cuplikan[$g->id]->created_at->toIso8601String() : null,
                'cuplikan' => isset($cuplikan[$g->id]) ? str($cuplikan[$g->id]->isi)->squish()->limit(80)->toString() : null,
                'cuplikan_dari_guru' => isset($cuplikan[$g->id]) && $cuplikan[$g->id]->pengirim_id === $g->user_id,
            ])
            ->sort(fn (array $a, array $b) => [$b['belum_dibaca'] > 0, $b['terakhir'] ?? '', $a['nama']] <=> [$a['belum_dibaca'] > 0, $a['terakhir'] ?? '', $b['nama']])
            ->values();
    }

    // Isi satu percakapan, terlama dulu, dilihat dari sisi $penonton (untuk tombol hapus & tanda "dibaca")
    public function pesan(Guru $guru, User $penonton): Collection
    {
        return ForumPesan::where('guru_id', $guru->id)
            ->with('pengirim:id,name')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (ForumPesan $p) => [
                'id' => $p->id,
                'isi' => $p->isi,
                'dari_guru' => $p->pengirim_id === $guru->user_id,
                'milik_saya' => $p->pengirim_id === $penonton->id,
                'pengirim' => $p->pengirim->name,
                'created_at' => $p->created_at->toIso8601String(),
                'dibaca_at' => $p->dibaca_at?->toIso8601String(),
                'bisa_dihapus' => $p->bisaDihapusOleh($penonton),
            ]);
    }

    // Pesan dari pihak lain di percakapan ini ditandai dibaca saat $pembaca membukanya
    public function tandaiDibaca(Guru $guru, User $pembaca): int
    {
        return ForumPesan::where('guru_id', $guru->id)
            ->whereNull('dibaca_at')
            ->when(
                $guru->user_id === $pembaca->id,
                fn ($q) => $q->where('pengirim_id', '!=', $guru->user_id), // guru membaca balasan Kepala Sekolah
                fn ($q) => $q->where('pengirim_id', $guru->user_id), // Kepala Sekolah membaca pesan guru
            )
            ->update(['dibaca_at' => now()]);
    }

    public function kirim(Guru $guru, User $pengirim, string $isi): ForumPesan
    {
        $isi = trim($isi);

        if (! $this->bolehBuka($pengirim, $guru)) {
            throw ValidationException::withMessages(['isi' => 'Anda tidak bisa menulis di percakapan ini.']);
        }
        if (! $guru->status_aktif) {
            throw ValidationException::withMessages(['isi' => 'Guru ini sudah tidak aktif; percakapannya hanya bisa dibaca.']);
        }
        if ($isi === '') {
            throw ValidationException::withMessages(['isi' => 'Pesan tidak boleh kosong.']);
        }
        if (mb_strlen($isi) > self::PANJANG_MAKS) {
            throw ValidationException::withMessages(['isi' => 'Pesan paling panjang '.number_format(self::PANJANG_MAKS, 0, ',', '.').' karakter.']);
        }

        return ForumPesan::create(['guru_id' => $guru->id, 'pengirim_id' => $pengirim->id, 'isi' => $isi]);
    }

    // Hanya pengirimnya, dalam 15 menit setelah dikirim (salah ketik); sesudah itu pesan menjadi arsip tetap
    public function hapus(ForumPesan $pesan, User $oleh): void
    {
        if (! $pesan->bisaDihapusOleh($oleh)) {
            throw ValidationException::withMessages([
                'pesan' => 'Pesan hanya bisa dihapus oleh pengirimnya dalam '.ForumPesan::MENIT_BOLEH_HAPUS.' menit setelah dikirim.',
            ]);
        }

        $pesan->delete();
    }

    // Angka badge menu & kartu Beranda: Kepala Sekolah = pesan guru yang belum dibaca;
    // guru = balasan Kepala Sekolah di percakapannya yang belum dibaca
    public function jumlahBelumDibaca(User $user): int
    {
        $jumlah = $user->hasRole('kepala_sekolah') ? $this->pesanGuruBelumDibaca()->count() : 0;

        if ($guru = $this->guruMilik($user)) {
            $jumlah += ForumPesan::where('guru_id', $guru->id)->where('pengirim_id', '!=', $user->id)->whereNull('dibaca_at')->count();
        }

        return $jumlah;
    }

    // Kartu Beranda "Tugas Saya" (bentuk sama dengan RingkasanTugasService::kartu)
    public function kartuTugas(User $user): array
    {
        $kartu = [];

        if ($user->hasRole('kepala_sekolah')) {
            $kartu[] = [
                'judul' => 'Pesan guru belum dibaca', 'jumlah' => $this->pesanGuruBelumDibaca()->count(),
                'keterangan' => 'Pesan di Forum Guru yang belum Anda buka.', 'href' => route('jurnal-guru.forum.index', [], false), 'nada' => 'tugas',
            ];
        }

        if ($guru = $this->guruMilik($user)) {
            $kartu[] = [
                'judul' => 'Balasan Kepala Sekolah', 'jumlah' => ForumPesan::where('guru_id', $guru->id)->where('pengirim_id', '!=', $user->id)->whereNull('dibaca_at')->count(),
                'keterangan' => 'Pesan baru di forum Anda dengan Kepala Sekolah.', 'href' => route('jurnal-guru.forum.show', $guru, false), 'nada' => 'tugas',
            ];
        }

        return $kartu;
    }

    private function pesanGuruBelumDibaca()
    {
        return ForumPesan::query()
            ->join('guru', 'guru.id', '=', 'forum_pesan.guru_id')
            ->whereColumn('forum_pesan.pengirim_id', 'guru.user_id')
            ->whereNull('forum_pesan.dibaca_at');
    }
}
