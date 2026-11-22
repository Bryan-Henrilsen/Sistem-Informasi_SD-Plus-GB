<?php

namespace App\Domains\LesEkskul\Services;

use App\Domains\LesEkskul\Enums\StatusKonfirmasiTransfer;
use App\Domains\LesEkskul\Enums\StatusTagihan;
use App\Domains\LesEkskul\Models\Pendaftaran;
use App\Domains\LesEkskul\Models\Tagihan;
use App\Domains\LesEkskul\Models\Transaksi;
use App\Enums\StatusSekolah;
use App\Models\Guru;
use App\Models\Kelas;
use App\Models\Siswa;
use App\Models\SiswaKelas;
use App\Models\TahunAjaran;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

// Data master Siswa: tambah/ubah, anak guru, pindah kelas, keluar sekolah, hapus (Ringkasan v1.11 #44)
class DataSiswaService
{
    public function __construct(private PerubahanPendaftaranService $perubahan) {}

    // Siswa satu tahun ajaran urut kelas lalu nama; $filter: kelas, nama, status
    public function daftar(TahunAjaran $tahunAjaran, array $filter): LengthAwarePaginator
    {
        return SiswaKelas::query()
            ->select('siswa_kelas.*')
            ->join('kelas', 'kelas.id', '=', 'siswa_kelas.kelas_id')
            ->join('siswa', 'siswa.id', '=', 'siswa_kelas.siswa_id')
            ->whereNull('siswa.deleted_at')
            ->where('siswa_kelas.tahun_ajaran_id', $tahunAjaran->id)
            ->when($filter['kelas'] ?? null, fn (Builder $q, $kelas) => $q->where('siswa_kelas.kelas_id', $kelas))
            ->when($filter['nama'] ?? null, fn (Builder $q, $nama) => $q->where('siswa.nama', 'ilike', "%{$nama}%"))
            ->when($filter['status'] ?? null, fn (Builder $q, $status) => $q->where('siswa.status_sekolah', $status))
            ->with(['kelas', 'siswa' => fn ($q) => $q->with('anakGuru.user:id,name')->withCount(['orangTua', 'pendaftaran as kegiatan_aktif' => fn ($q) => $q->aktif()])])
            ->orderBy('kelas.jenjang')
            ->orderBy('kelas.nama_kelas')
            ->orderBy('siswa.nama')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (SiswaKelas $sk) => $this->baris($sk));
    }

    // Tambah siswa baru ke kelas tahun ajaran ini, atau ubah data siswa (termasuk pindah kelas & anak guru).
    // $data: nama, jenis_kelamin, nis, nisn, tanggal_lahir, anak_guru_id, kelas_id. Hasilnya [siswa, pesan tambahan]
    public function simpan(TahunAjaran $tahunAjaran, array $data, ?Siswa $siswa = null): array
    {
        return DB::transaction(function () use ($tahunAjaran, $data, $siswa) {
            $kelas = Kelas::where('tahun_ajaran_id', $tahunAjaran->id)->findOrFail($data['kelas_id']);
            $nama = Str::squish($data['nama']);
            $anakGuruId = filled($data['anak_guru_id'] ?? null) ? (int) $data['anak_guru_id'] : null;

            $this->periksaNama($nama, $kelas, $siswa);
            if ($anakGuruId && $anakGuruId !== $siswa?->anak_guru_id && ! Guru::whereKey($anakGuruId)->where('status_aktif', true)->exists()) {
                throw ValidationException::withMessages(['anak_guru_id' => 'Pilih guru SD Plus yang masih aktif.']);
            }

            $anakGuruBerubah = $siswa !== null && $siswa->anak_guru_id !== $anakGuruId;
            $siswa ??= new Siswa;
            $siswa->fill([
                'nama' => $nama,
                'jenis_kelamin' => $data['jenis_kelamin'] ?? null,
                'nis' => filled($data['nis'] ?? null) ? trim($data['nis']) : null,
                'nisn' => filled($data['nisn'] ?? null) ? trim($data['nisn']) : null,
                'tanggal_lahir' => $data['tanggal_lahir'] ?? null,
                'anak_guru_id' => $anakGuruId,
            ])->save();

            $pesan = [];
            $sekarang = $siswa->riwayatKelas()->where('tahun_ajaran_id', $tahunAjaran->id)->first();

            if (! $sekarang) {
                SiswaKelas::create(['siswa_id' => $siswa->id, 'kelas_id' => $kelas->id]);
            } elseif ($sekarang->kelas_id !== $kelas->id) {
                $pesan[] = $this->pindahKelas($sekarang, $kelas);
            }

            if ($anakGuruBerubah) {
                $jumlah = $this->sesuaikanDiskon($siswa->fresh());
                $pesan[] = $jumlah > 0 ? "Diskon anak guru {$jumlah} tagihan yang belum dibayar disesuaikan." : null;
            }

            return [$siswa, implode(' ', array_filter($pesan))];
        });
    }

    // Keluar sekolah: semua kegiatan aktif diberhentikan per tanggal efektif dengan aturan uang FR-10/FR-22 (#44)
    public function keluar(Siswa $siswa, string $tanggal, string $alasan, User $oleh): array
    {
        return DB::transaction(function () use ($siswa, $tanggal, $alasan, $oleh) {
            $siswa = Siswa::whereKey($siswa->id)->lockForUpdate()->firstOrFail();

            if ($siswa->status_sekolah !== StatusSekolah::Aktif) {
                throw ValidationException::withMessages(['siswa' => "{$siswa->nama} sudah tidak aktif di sekolah."]);
            }

            $refund = 0;
            $kegiatan = $siswa->pendaftaran()->aktif()->get();
            foreach ($kegiatan as $p) {
                // konfirmasi = true: peringatan batas semester dilewati, keluar sekolah adalah alasan sah
                $hasil = $this->perubahan->berhenti($p, $tanggal, 'Keluar sekolah: '.trim($alasan), true, $oleh);
                $refund += $hasil['refund'];
            }

            $siswa->update(['status_sekolah' => StatusSekolah::PindahSekolah]);

            return ['kegiatan' => $kegiatan->count(), 'refund' => $refund];
        });
    }

    // Hanya mengembalikan status; kegiatan didaftarkan ulang secara manual (#44)
    public function aktifkanKembali(Siswa $siswa): void
    {
        if ($siswa->status_sekolah !== StatusSekolah::PindahSekolah) {
            throw ValidationException::withMessages(['siswa' => "{$siswa->nama} tidak berstatus keluar sekolah."]);
        }

        $siswa->update(['status_sekolah' => StatusSekolah::Aktif]);
    }

    // Hapus siswa salah input: belum punya pendaftaran, transaksi, maupun klaim orang tua (soft delete)
    public function hapus(Siswa $siswa): void
    {
        DB::transaction(function () use ($siswa) {
            if (! $this->bisaDihapus($siswa)) {
                throw ValidationException::withMessages([
                    'siswa' => "{$siswa->nama} sudah punya pendaftaran, transaksi, atau diklaim orang tua, jadi tidak bisa dihapus.",
                ]);
            }

            $siswa->riwayatKelas()->delete(); // supaya tidak terhitung sebagai siswa kelas
            $siswa->delete();
        });
    }

    // Pindah kelas: jenjang sama → les aktif ikut pindah ke les kelas tujuan; jenjang lain hanya bila tanpa kegiatan aktif
    private function pindahKelas(SiswaKelas $sekarang, Kelas $tujuan): ?string
    {
        $asal = $sekarang->kelas;
        $aktif = Pendaftaran::aktif()->where('siswa_id', $sekarang->siswa_id)->get();

        if ($asal->jenjang !== $tujuan->jenjang && $aktif->isNotEmpty()) {
            throw ValidationException::withMessages([
                'kelas_id' => "Pindah ke jenjang lain hanya bila siswa tidak ikut kegiatan apa pun. Berhentikan dulu {$aktif->count()} kegiatan aktifnya.",
            ]);
        }

        $les = $aktif->first(fn (Pendaftaran $p) => $p->les_tambahan_id !== null && $p->lesTambahan->kelas_id === $asal->id);
        $pesan = null;

        if ($les) {
            $lesTujuan = $tujuan->lesTambahan()->first()
                ?? throw ValidationException::withMessages(['kelas_id' => "Kelas {$tujuan->nama_lengkap} tidak punya les tambahan, padahal siswa ikut les kelas {$asal->nama_lengkap}."]);
            $les->update(['les_tambahan_id' => $lesTujuan->id]);
            $pesan = "Les tambahan ikut pindah ke les kelas {$tujuan->nama_lengkap}.";
        }

        $sekarang->update(['kelas_id' => $tujuan->id]);

        return $pesan;
    }

    // Diskon anak guru untuk tagihan tahun ajaran aktif yang belum ada pembayaran sama sekali dan tidak sedang dikonfirmasi
    private function sesuaikanDiskon(Siswa $siswa): int
    {
        $tahunAjaran = TahunAjaran::aktif()->first();
        if (! $tahunAjaran) {
            return 0;
        }

        $berhak = $siswa->berhakDiskonAnakGuru();
        $tagihan = Tagihan::whereHas('pendaftaran', fn (Builder $q) => $q->where('siswa_id', $siswa->id))
            ->where('status', StatusTagihan::Belum)
            ->whereBetween('bulan_tagihan', [$tahunAjaran->tanggal_mulai->toDateString(), $tahunAjaran->tanggal_selesai->toDateString()])
            ->whereDoesntHave('transaksi')
            ->whereDoesntHave('konfirmasiTransfer', fn (Builder $q) => $q->where('status', StatusKonfirmasiTransfer::Menunggu))
            ->lockForUpdate()
            ->get();

        $diubah = 0;
        foreach ($tagihan as $t) {
            $diskon = $berhak ? intdiv($t->nominal, 2) : 0; // sama dengan TagihanService::hitungDiskon (FR-9)
            if ($t->diskon !== $diskon) {
                $t->update(['diskon' => $diskon]);
                $diubah++;
            }
        }

        return $diubah;
    }

    private function periksaNama(string $nama, Kelas $kelas, ?Siswa $siswa): void
    {
        $ganda = SiswaKelas::where('kelas_id', $kelas->id)
            ->when($siswa, fn ($q) => $q->where('siswa_id', '!=', $siswa->id))
            ->whereHas('siswa', fn ($q) => $q->whereRaw('LOWER(nama) = ?', [Str::lower($nama)]))
            ->exists();

        if ($ganda) {
            throw ValidationException::withMessages(['nama' => "{$nama} sudah ada di kelas {$kelas->nama_lengkap}."]);
        }
    }

    private function bisaDihapus(Siswa $siswa): bool
    {
        return ! $siswa->pendaftaran()->exists()
            && ! Transaksi::where('siswa_id', $siswa->id)->exists()
            && ! $siswa->orangTua()->exists();
    }

    private function baris(SiswaKelas $sk): array
    {
        $s = $sk->siswa;

        return [
            'id' => $s->id,
            'nama' => $s->nama,
            'jenis_kelamin' => $s->jenis_kelamin,
            'nis' => $s->nis,
            'nisn' => $s->nisn,
            'tanggal_lahir' => $s->tanggal_lahir?->toDateString(),
            'kelas_id' => $sk->kelas_id,
            'kelas' => $sk->kelas->nama_lengkap,
            'jenjang' => $sk->kelas->jenjang,
            'anak_guru_id' => $s->anak_guru_id,
            'anak_guru' => $s->anakGuru?->user?->name,
            'orang_tua' => $s->orang_tua_count,
            'kegiatan_aktif' => $s->kegiatan_aktif,
            'status' => $s->status_sekolah,
            'bisa_dihapus' => $s->orang_tua_count === 0 && $this->bisaDihapus($s),
        ];
    }
}
