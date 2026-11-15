<?php

namespace App\Domains\LesEkskul\Services;

use App\Domains\LesEkskul\Enums\JenisPengajar;
use App\Domains\LesEkskul\Enums\KategoriPendamping;
use App\Domains\LesEkskul\Models\Ekstrakurikuler;
use App\Domains\LesEkskul\Models\LesTambahan;
use App\Domains\LesEkskul\Models\Pendaftaran;
use App\Domains\LesEkskul\Models\PengajarEkskul;
use App\Models\TahunAjaran;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Data pengajar ekskul dan penugasannya (FR-16b, FR-18; Ringkasan v1.11 #33).
// "Unit honor" = bagian yang dihitung honornya sendiri: kegiatan induk (digabung) atau tiap kelas paralel (per kelas)
class PengajarEkskulService
{
    // Pengajar internal = akun guru/staf (satu akun hanya satu baris); eksternal = nama tanpa akun
    public function simpanPengajar(array $data, ?PengajarEkskul $pengajar = null): PengajarEkskul
    {
        $jenis = JenisPengajar::from($data['jenis']);

        if ($jenis === JenisPengajar::Internal) {
            $userId = (int) ($data['user_id'] ?? 0) ?: throw $this->gagal('user_id', 'Pilih akun guru/staf untuk pengajar internal.');
            $sudahAda = PengajarEkskul::where('user_id', $userId)->when($pengajar, fn ($q) => $q->whereKeyNot($pengajar->id))->exists();
            if ($sudahAda) {
                throw $this->gagal('user_id', 'Akun ini sudah terdaftar sebagai pengajar.');
            }
        } elseif (blank($data['nama'] ?? null)) {
            throw $this->gagal('nama', 'Nama pengajar eksternal wajib diisi.');
        }

        // Kolom no_hp wajib di skema (A4 pengajar_ekskul): kontak pengajar untuk koordinasi kegiatan
        if (blank($data['no_hp'] ?? null)) {
            throw $this->gagal('no_hp', 'No HP pengajar wajib diisi.');
        }

        $isi = [
            'jenis' => $jenis,
            'user_id' => $jenis === JenisPengajar::Internal ? (int) $data['user_id'] : null,
            // Nama pengajar internal mengikuti akunnya supaya tidak dobel penulisan
            'nama' => $jenis === JenisPengajar::Internal ? User::findOrFail((int) $data['user_id'])->name : trim($data['nama']),
            'no_hp' => trim($data['no_hp']),
            'status_aktif' => (bool) ($data['status_aktif'] ?? true),
        ];

        return $pengajar ? tap($pengajar)->update($isi) : PengajarEkskul::create($isi);
    }

    // Kegiatan induk berkelas paralel: honor digabung atau per kelas (#33)
    public function aturModeHonor(Ekstrakurikuler $induk, bool $perKelas): void
    {
        $this->pastikanInduk($induk);
        $induk->update(['honor_per_kelas_paralel' => $perKelas]);
    }

    // Kategori pendamping seni/olahraga, hanya untuk pengelompokan rekap (FR-18)
    public function aturKategori(Ekstrakurikuler $induk, ?string $kategori): void
    {
        $this->pastikanInduk($induk);
        $induk->update(['kategori_pendamping' => $kategori ? KategoriPendamping::from($kategori) : null]);
    }

    // Pengajar dicatat di baris kegiatan yang benar-benar diajar (induk tanpa kelas paralel, atau kelas paralelnya)
    public function aturPengajar(Ekstrakurikuler $kegiatan, array $pengajarIds): void
    {
        DB::transaction(fn () => $kegiatan->pengajar()->sync(array_map('intval', $pengajarIds)));
    }

    // Pendamping dicatat di unit honornya: induk bila digabung, kelas paralel bila per kelas (FR-16d, FR-18)
    public function aturPendamping(Ekstrakurikuler $kegiatan, array $userIds): void
    {
        DB::transaction(fn () => $kegiatan->pendamping()->sync(array_map('intval', $userIds)));
    }

    public function unitHonor(Ekstrakurikuler $induk): Collection
    {
        $kelas = $induk->kelasParalel;

        return $kelas->isNotEmpty() && $induk->honor_per_kelas_paralel ? $kelas->values() : collect([$induk]);
    }

    // Pengajar unik sebuah unit; unit gabungan memakai pengajar induk dan seluruh kelas paralelnya (FR-16b)
    public function pengajarUnit(Ekstrakurikuler $unit): Collection
    {
        $baris = $unit->adalahKelasParalel() || $unit->kelasParalel->isEmpty() || $unit->honor_per_kelas_paralel
            ? collect([$unit])
            : $unit->kelasParalel->concat([$unit]);

        return $baris->flatMap(fn (Ekstrakurikuler $e) => $e->pengajar)->unique('id')->values();
    }

    // Data yang membuat honor kegiatan ini belum bisa dihitung (#33); kosong = lengkap
    public function masalahKegiatan(Ekstrakurikuler $induk): array
    {
        $masalah = [];

        foreach ($this->unitHonor($induk) as $unit) {
            $pengajar = $this->pengajarUnit($unit);
            $jenis = $pengajar->pluck('jenis')->unique();

            if ($pengajar->isEmpty()) {
                $masalah[] = "{$unit->nama}: belum ada pengajar.";
            } elseif ($jenis->count() > 1) {
                $masalah[] = "{$unit->nama}: pengajar campuran internal dan eksternal; pisahkan per kelas atau samakan jenisnya.";
            } elseif ($jenis->first() === JenisPengajar::Eksternal && $unit->pendamping->isEmpty()) {
                $masalah[] = "{$unit->nama}: pengajar eksternal wajib punya guru pendamping.";
            }
        }

        if ($induk->honor_per_kelas_paralel && $induk->kelasParalel->isNotEmpty()) {
            $belumDiassign = Pendaftaran::aktif()->where('ekstrakurikuler_id', $induk->id)->count();

            if ($belumDiassign > 0) {
                $masalah[] = "{$belumDiassign} siswa {$induk->nama} belum di-assign ke kelas; honor per kelas butuh kelas setiap siswa.";
            }
        }

        return $masalah;
    }

    // Les tambahan: pengajarnya wali kelas (FR-16c); kelas tanpa wali membuat honor les belum bisa dihitung
    public function masalahLes(TahunAjaran $tahunAjaran): array
    {
        return LesTambahan::where('tahun_ajaran_id', $tahunAjaran->id)
            ->with('kelas.waliKelas.user')
            ->get()
            ->filter(fn (LesTambahan $les) => ! $les->kelas->waliKelas?->user)
            ->sortBy(fn (LesTambahan $les) => sprintf('%d%s', $les->kelas->jenjang, $les->kelas->nama_kelas))
            ->map(fn (LesTambahan $les) => "Kelas {$les->kelas->nama_lengkap}: belum punya wali kelas (pengajar les).")
            ->values()
            ->all();
    }

    private function pastikanInduk(Ekstrakurikuler $ekskul): void
    {
        if ($ekskul->adalahKelasParalel()) {
            throw $this->gagal('ekskul', 'Pengaturan ini hanya untuk kegiatan induk.');
        }
    }

    private function gagal(string $field, string $pesan): ValidationException
    {
        return ValidationException::withMessages([$field => $pesan]);
    }
}
