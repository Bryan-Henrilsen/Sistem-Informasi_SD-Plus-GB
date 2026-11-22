<?php

namespace App\Domains\LesEkskul\Services;

use App\Models\JenisTugas;
use App\Models\MataPelajaran;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

// Data master Mata Pelajaran & Jenis Tugas oleh Waka Kurikulum & Kepala Sekolah (Ringkasan v1.11 #47)
class MataPelajaranService
{
    public function daftar(): Collection
    {
        $dipakai = DB::table('kisi_kisi_tugas')->whereNotNull('mapel_id')->distinct()->pluck('mapel_id')->flip();

        return MataPelajaran::withCount('guru')
            ->orderByDesc('aktif')
            ->orderBy('nama')
            ->get()
            ->map(fn (MataPelajaran $m) => [
                'id' => $m->id,
                'kode' => $m->kode,
                'nama' => $m->nama,
                'aktif' => $m->aktif,
                'jumlah_guru' => $m->guru_count,
                'dipakai_kisi_kisi' => $dipakai->has($m->id),
                'bisa_dihapus' => ! $dipakai->has($m->id) && $m->guru_count === 0,
            ]);
    }

    public function jenisTugas(): Collection
    {
        return JenisTugas::orderBy('id')->get(['id', 'kode', 'nama']);
    }

    // Tambah/ubah mapel; kode unik tanpa membedakan huruf besar-kecil dan disimpan huruf besar
    public function simpan(array $data, ?MataPelajaran $mapel = null): MataPelajaran
    {
        $kode = Str::upper(trim($data['kode']));
        $dipakai = MataPelajaran::whereRaw('UPPER(kode) = ?', [$kode])->when($mapel, fn ($q) => $q->whereKeyNot($mapel->id))->exists();

        if ($dipakai) {
            throw ValidationException::withMessages(['kode' => "Kode {$kode} sudah dipakai mata pelajaran lain."]);
        }

        $mapel ??= new MataPelajaran;
        $mapel->fill(['kode' => $kode, 'nama' => Str::squish($data['nama'])])->save();

        return $mapel;
    }

    public function aturAktif(MataPelajaran $mapel, bool $aktif): void
    {
        $mapel->update(['aktif' => $aktif]);
    }

    // Hapus hanya mapel yang belum pernah dipakai di kisi-kisi tugas maupun data mapel guru
    public function hapus(MataPelajaran $mapel): void
    {
        if (DB::table('kisi_kisi_tugas')->where('mapel_id', $mapel->id)->exists() || $mapel->guru()->exists()) {
            throw ValidationException::withMessages([
                'mapel' => "{$mapel->nama} sudah dipakai di kisi-kisi atau diampu guru. Nonaktifkan saja bila tidak dipakai lagi.",
            ]);
        }

        $mapel->delete();
    }

    // Jenis tugas tetap tiga (kodenya dipakai program Jadwal Guru); hanya nama tampilan yang bisa diubah
    public function ubahNamaJenisTugas(JenisTugas $jenis, string $nama): void
    {
        $jenis->update(['nama' => Str::squish($nama)]);
    }
}
