<?php

namespace App\Domains\JadwalGuru\Http\Controllers;

use App\Domains\JadwalGuru\Models\JadwalMengajar;
use App\Domains\JadwalGuru\Models\WaktuPelajaran;
use App\Http\Controllers\Controller;
use App\Models\Guru;
use App\Models\Kelas;
use App\Models\TahunAjaran;
use Inertia\Inertia;
use Inertia\Response;

class HasilJadwalController extends Controller
{
    /** Menampilkan hasil jadwal aktual per kelas atau per guru. */
    public function index(): Response
    {
        $tahunAjaran = TahunAjaran::aktif()->firstOrFail();

        $daftarKelas = Kelas::query()
            ->where('tahun_ajaran_id', $tahunAjaran->id)
            ->orderBy('jenjang')
            ->orderBy('nama_kelas')
            ->get(['id', 'nama_kelas', 'nama_pelindung', 'jenjang'])
            ->map(fn (Kelas $kelas): array => [
                'id' => (int) $kelas->id,
                'nama_kelas' => $kelas->nama_kelas,
                'nama_lengkap' => $kelas->nama_lengkap,
                'jenjang' => (int) $kelas->jenjang,
            ])
            ->values();

        $daftarGuru = Guru::query()
            ->where('status_aktif', true)
            ->with('user:id,name')
            ->orderBy('kode_guru')
            ->get(['id', 'user_id', 'kode_guru'])
            ->map(fn (Guru $guru): array => [
                'id' => (int) $guru->id,
                'kode_guru' => $guru->kode_guru,
                'nama' => $guru->user?->name ?? $guru->kode_guru,
            ])
            ->values();

        $waktuPelajaran = WaktuPelajaran::query()
            ->where('tahun_ajaran_id', $tahunAjaran->id)
            ->with('hariBerlaku')
            ->orderBy('urutan')
            ->get()
            ->map(fn (WaktuPelajaran $slot): array => [
                'id' => (int) $slot->id,
                'urutan' => (int) $slot->urutan,
                'jam_ke' => $slot->jam_ke === null ? null : (int) $slot->jam_ke,
                'nama' => $slot->nama,
                'jam_mulai' => (string) $slot->jam_mulai,
                'jam_selesai' => (string) $slot->jam_selesai,
                'jenis_kegiatan' => $slot->jenis_kegiatan,
                'jenjang_min' => (int) $slot->jenjang_min,
                'jenjang_max' => (int) $slot->jenjang_max,
                'hari' => $slot->hariBerlaku
                    ->pluck('hari')
                    ->map(fn ($hari): int => (int) $hari)
                    ->values(),
            ])
            ->values();

        $jadwal = JadwalMengajar::query()
            ->whereHas('kisiKisiTugas', fn ($query) =>
                $query->where('tahun_ajaran_id', $tahunAjaran->id)
            )
            ->with([
                'guru.user:id,name',
                'kelas:id,nama_kelas,nama_pelindung,jenjang',
                'waktuPelajaran:id,urutan,jam_ke,nama,jam_mulai,jam_selesai,jenis_kegiatan',
                'jenisTugas:id,kode,nama',
                'kisiKisiTugas.mataPelajaran:id,kode,nama',
            ])
            ->orderBy('hari')
            ->orderBy('waktu_pelajaran_id')
            ->orderBy('id')
            ->get()
            ->map(function (JadwalMengajar $item): array {
                $mataPelajaran = $item->kisiKisiTugas?->mataPelajaran;

                return [
                    'id' => (int) $item->id,
                    'hari' => (int) $item->hari,
                    'waktu_pelajaran_id' => (int) $item->waktu_pelajaran_id,
                    'parent_jadwal_id' => $item->parent_jadwal_id === null
                        ? null
                        : (int) $item->parent_jadwal_id,
                    'guru' => [
                        'id' => (int) $item->guru_id,
                        'kode_guru' => $item->guru?->kode_guru,
                        'nama' => $item->guru?->user?->name
                            ?? $item->guru?->kode_guru
                            ?? 'Nama guru belum tersedia',
                    ],
                    'kelas' => $item->kelas ? [
                        'id' => (int) $item->kelas->id,
                        'nama_kelas' => $item->kelas->nama_kelas,
                        'nama_lengkap' => $item->kelas->nama_lengkap,
                        'jenjang' => (int) $item->kelas->jenjang,
                    ] : null,
                    'mata_pelajaran' => $mataPelajaran ? [
                        'id' => (int) $mataPelajaran->id,
                        'kode' => $mataPelajaran->kode,
                        'nama' => $mataPelajaran->nama,
                    ] : null,
                    'jenis_tugas' => [
                        'kode' => $item->jenisTugas?->kode,
                        'nama' => $item->jenisTugas?->nama,
                    ],
                ];
            })
            ->values();

        return Inertia::render('jadwal-guru/HasilJadwal', [
            'tahunAjaran' => [
                'id' => (int) $tahunAjaran->id,
                'nama' => $tahunAjaran->nama,
            ],
            'daftarKelas' => $daftarKelas,
            'daftarGuru' => $daftarGuru,
            'waktuPelajaran' => $waktuPelajaran,
            'jadwal' => $jadwal,
        ]);
    }
}
