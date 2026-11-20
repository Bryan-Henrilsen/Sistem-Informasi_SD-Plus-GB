<?php

namespace App\Domains\LesEkskul\Http\Controllers;

use App\Domains\LesEkskul\Models\Pendaftaran;
use App\Domains\LesEkskul\Services\RekapIuranService;
use App\Domains\LesEkskul\Services\TagihanService;
use App\Http\Controllers\Controller;
use App\Models\Kelas;
use App\Models\TahunAjaran;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class TagihanController extends Controller
{
    // Rekap Iuran: pengganti buku rekap TU, per buku kegiatan, urut kelas lalu abjad (FR-13b, Ringkasan v1.11 #40)
    public function index(Request $request, RekapIuranService $rekap): Response
    {
        $daftarTahun = TahunAjaran::orderByDesc('tanggal_mulai')->get(['id', 'nama', 'status_aktif']);
        $tahunAjaran = $request->integer('tahun')
            ? TahunAjaran::findOrFail($request->integer('tahun'))
            : TahunAjaran::aktif()->firstOrFail();
        $filter = $request->only('buku', 'kelas', 'status', 'nama');

        $semua = $rekap->baris($tahunAjaran);
        $bulan = $rekap->bulan($tahunAjaran);

        $baris = $semua
            ->when($filter['buku'] ?? null, fn (Collection $c, string $buku) => $c->where('buku', $buku))
            ->when($filter['kelas'] ?? null, fn (Collection $c, string $kelas) => $c->where('kelas_id', (int) $kelas))
            ->when(($filter['status'] ?? null) === 'tunggakan', fn (Collection $c) => $c->where('tunggakan', '>', 0))
            ->when(($filter['status'] ?? null) === 'menunggu', fn (Collection $c) => $c->where('menunggu', true))
            ->when($filter['nama'] ?? null, fn (Collection $c, string $nama) => $c
                ->filter(fn (array $b) => Str::contains($b['siswa']['nama'], $nama, ignoreCase: true)))
            ->map(fn (array $b) => Arr::except($b, ['urut_buku', 'urut_kelas']))
            ->values();

        return Inertia::render('les-ekskul/tagihan/index', [
            'tahunAjaran' => ['id' => $tahunAjaran->id, 'nama' => $tahunAjaran->nama],
            'daftarTahun' => $daftarTahun->map(fn (TahunAjaran $t) => ['id' => $t->id, 'nama' => $t->nama]),
            'bulan' => $bulan,
            'buku' => $rekap->daftarBuku($semua),
            'baris' => $baris,
            'ringkasan' => $rekap->ringkasan($semua, $bulan),
            'kartu' => [
                'total_tunggakan' => $semua->sum('tunggakan'),
                'siswa_menunggak' => $semua->where('tunggakan', '>', 0)->pluck('siswa.id')->unique()->count(),
                'menunggu_verifikasi' => $semua->where('menunggu', true)->count(),
            ],
            'kelas' => Kelas::where('tahun_ajaran_id', $tahunAjaran->id)->orderBy('jenjang')->orderBy('nama_kelas')->get()
                ->map(fn (Kelas $k) => ['id' => $k->id, 'nama' => $k->nama_lengkap]),
            'filter' => $filter,
            'bulanIni' => today()->format('Y-m'),
            // Tombol Catat pembayaran dan Verifikasi transfer di rincian kotak hanya untuk TU (#40)
            'bisaCatat' => $request->user()->hasRole('tu'),
        ]);
    }

    // Bayar di muka: tambah satu tagihan bulan berikutnya untuk pendaftaran ini (FR-9, Catatan v1.8)
    public function bayarDiMuka(Request $request, TagihanService $service): RedirectResponse
    {
        $data = $request->validate(['pendaftaran_id' => ['required', 'integer', 'exists:pendaftaran,id']]);

        $tagihan = $service->buatBulanBerikutnya(Pendaftaran::findOrFail($data['pendaftaran_id']));

        Inertia::flash('toast', $tagihan
            ? ['type' => 'success', 'message' => 'Tagihan '.$tagihan->bulan_tagihan->locale('id')->translatedFormat('F Y').' ditambahkan.']
            : ['type' => 'warning', 'message' => 'Tidak ada lagi bulan yang ditagih untuk kegiatan ini sampai akhir tahun ajaran.']);

        return back();
    }
}
