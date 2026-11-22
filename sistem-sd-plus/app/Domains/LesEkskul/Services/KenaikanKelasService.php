<?php

namespace App\Domains\LesEkskul\Services;

use App\Enums\StatusSekolah;
use App\Models\Kelas;
use App\Models\Siswa;
use App\Models\SiswaKelas;
use App\Models\TahunAjaran;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

// Kenaikan kelas massal per kelas ke tahun ajaran berikutnya (Ringkasan v1.11 #45). Kelas tidak diacak setiap tahun,
// jadi 2A naik ke 3A; kelas 6 lulus; siswa tinggal kelas masuk kelas berjenjang sama
class KenaikanKelasService
{
    // Tahun ajaran asal = tahun ajaran sebelum $tujuan yang punya kelas (biasanya tahun ajaran aktif)
    public function asal(TahunAjaran $tujuan): ?TahunAjaran
    {
        return TahunAjaran::where('tanggal_mulai', '<', $tujuan->tanggal_mulai->toDateString())
            ->whereIn('id', Kelas::select('tahun_ajaran_id'))
            ->orderByDesc('tanggal_mulai')
            ->first();
    }

    // Kelas asal beserta usulan kelas tujuan dan siswa aktifnya (yang sudah punya kelas di tahun tujuan ditandai)
    public function pratinjau(TahunAjaran $asal, TahunAjaran $tujuan): Collection
    {
        $kelasTujuan = Kelas::where('tahun_ajaran_id', $tujuan->id)->get();
        $sudahBerkelas = SiswaKelas::where('tahun_ajaran_id', $tujuan->id)->with('kelas')->get()->keyBy('siswa_id');

        return Kelas::where('tahun_ajaran_id', $asal->id)
            ->with(['siswaKelas' => fn ($q) => $q->whereHas('siswa', fn ($s) => $s->where('status_sekolah', StatusSekolah::Aktif))->with('siswa:id,nama')])
            ->orderBy('jenjang')
            ->orderBy('nama_kelas')
            ->get()
            ->map(fn (Kelas $k) => [
                'id' => $k->id,
                'nama' => $k->nama_lengkap,
                'jenjang' => $k->jenjang,
                // null = lulus (kelas 6)
                'usulan_tujuan_id' => $k->jenjang === 6 ? null : $this->usulan($k, $kelasTujuan)?->id,
                'siswa' => $k->siswaKelas
                    ->sortBy(fn (SiswaKelas $sk) => $sk->siswa->nama)
                    ->map(fn (SiswaKelas $sk) => [
                        'id' => $sk->siswa_id,
                        'nama' => $sk->siswa->nama,
                        'sudah_di' => $sudahBerkelas->get($sk->siswa_id)?->kelas->nama_lengkap,
                    ])->values(),
            ]);
    }

    // $tujuanPerKelas = [kelas_asal_id => kelas_tujuan_id] (kelas 6 diabaikan: lulus);
    // $tinggal = [siswa_id => kelas_tujuan_id] untuk siswa tinggal kelas. Hasilnya jumlah naik, tinggal, lulus, dilewati
    public function jalankan(TahunAjaran $asal, TahunAjaran $tujuan, array $tujuanPerKelas, array $tinggal): array
    {
        return DB::transaction(function () use ($asal, $tujuan, $tujuanPerKelas, $tinggal) {
            TahunAjaran::whereKey($tujuan->id)->lockForUpdate()->first(); // dua klik bersamaan tidak membuat kelas ganda

            if ($tujuan->tanggal_mulai->lte($asal->tanggal_mulai)) {
                throw ValidationException::withMessages(['kenaikan' => 'Tahun ajaran tujuan harus sesudah tahun ajaran asal.']);
            }

            $kelasTujuan = Kelas::where('tahun_ajaran_id', $tujuan->id)->get()->keyBy('id');
            $sudahBerkelas = SiswaKelas::where('tahun_ajaran_id', $tujuan->id)->pluck('siswa_id')->flip();
            $hasil = ['naik' => 0, 'tinggal' => 0, 'lulus' => 0, 'dilewati' => 0];
            $salah = [];

            $kelasAsal = Kelas::where('tahun_ajaran_id', $asal->id)
                ->with(['siswaKelas' => fn ($q) => $q->whereHas('siswa', fn ($s) => $s->where('status_sekolah', StatusSekolah::Aktif))])
                ->get();

            foreach ($kelasAsal as $k) {
                $naikKe = $k->jenjang < 6 ? $kelasTujuan->get((int) ($tujuanPerKelas[$k->id] ?? 0)) : null;

                if ($k->jenjang < 6 && $k->siswaKelas->isNotEmpty() && (! $naikKe || $naikKe->jenjang !== $k->jenjang + 1)) {
                    $salah["tujuan.{$k->id}"] = 'Pilih kelas jenjang '.($k->jenjang + 1)." untuk siswa kelas {$k->nama_lengkap}.";

                    continue;
                }

                foreach ($k->siswaKelas as $sk) {
                    if ($sudahBerkelas->has($sk->siswa_id)) {
                        $hasil['dilewati']++;

                        continue;
                    }

                    if (isset($tinggal[$sk->siswa_id])) {
                        $ke = $kelasTujuan->get((int) $tinggal[$sk->siswa_id]);
                        if (! $ke || $ke->jenjang !== $k->jenjang) {
                            $salah["tinggal.{$sk->siswa_id}"] = "Siswa tinggal kelas harus masuk kelas jenjang {$k->jenjang}.";

                            continue;
                        }
                        SiswaKelas::create(['siswa_id' => $sk->siswa_id, 'kelas_id' => $ke->id]);
                        $hasil['tinggal']++;
                    } elseif ($k->jenjang === 6) {
                        Siswa::whereKey($sk->siswa_id)->update(['status_sekolah' => StatusSekolah::Lulus]);
                        $hasil['lulus']++;
                    } else {
                        SiswaKelas::create(['siswa_id' => $sk->siswa_id, 'kelas_id' => $naikKe->id]);
                        $hasil['naik']++;
                    }
                }
            }

            if ($salah !== []) {
                throw ValidationException::withMessages($salah); // dibatalkan seluruhnya
            }

            return $hasil;
        });
    }

    // Usulan: jenjang +1 dengan huruf sama (2A → 3A); bila tidak ada, satu-satunya kelas di jenjang itu
    private function usulan(Kelas $asal, Collection $kelasTujuan): ?Kelas
    {
        $jenjang = $asal->jenjang + 1;
        $namaUsulan = $jenjang.substr($asal->nama_kelas, strlen((string) $asal->jenjang));
        $sejenjang = $kelasTujuan->where('jenjang', $jenjang);

        return $sejenjang->first(fn (Kelas $k) => strcasecmp($k->nama_kelas, $namaUsulan) === 0)
            ?? ($sejenjang->count() === 1 ? $sejenjang->first() : null);
    }
}
