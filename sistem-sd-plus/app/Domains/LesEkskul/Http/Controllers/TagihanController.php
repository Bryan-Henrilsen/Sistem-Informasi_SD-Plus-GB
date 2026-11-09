<?php

namespace App\Domains\LesEkskul\Http\Controllers;

use App\Domains\LesEkskul\Enums\StatusTagihan;
use App\Domains\LesEkskul\Models\Pendaftaran;
use App\Domains\LesEkskul\Models\Tagihan;
use App\Domains\LesEkskul\Services\TagihanService;
use App\Http\Controllers\Controller;
use App\Models\Kelas;
use App\Models\Siswa;
use App\Models\TahunAjaran;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TagihanController extends Controller
{
    // Kartu iuran: satu baris per pendaftaran, satu kolom per bulan tagihan tahun ajaran aktif
    public function index(Request $request): Response
    {
        $tahunAjaran = TahunAjaran::aktif()->firstOrFail();
        $filter = $request->only('nama', 'kelas', 'status');

        $pendaftaran = Pendaftaran::query()
            ->whereHas('tagihan') // kegiatan berbiaya 0 tidak punya tagihan
            ->where(fn (Builder $q) => $q
                ->whereHas('lesTambahan', fn (Builder $q) => $q->where('tahun_ajaran_id', $tahunAjaran->id))
                ->orWhereHas('ekstrakurikuler', fn (Builder $q) => $q->where('tahun_ajaran_id', $tahunAjaran->id)))
            ->when($filter['nama'] ?? null, fn (Builder $q, string $nama) => $q
                ->whereHas('siswa', fn (Builder $q) => $q->where('nama', 'ilike', "%{$nama}%")))
            ->when($filter['kelas'] ?? null, fn (Builder $q, string $kelasId) => $q
                ->whereHas('siswa.riwayatKelas', fn (Builder $q) => $q->where('kelas_id', $kelasId)))
            ->when(($filter['status'] ?? null) === 'tunggakan', fn (Builder $q) => $q
                ->whereHas('tagihan', fn (Builder $q) => $q->tunggakan()))
            ->when(($filter['status'] ?? null) === 'lunas', fn (Builder $q) => $q
                ->whereDoesntHave('tagihan', fn (Builder $q) => $q->belumLunas()))
            ->with([
                'siswa.kelasTahunAktif.kelas',
                'lesTambahan.kelas',
                'ekstrakurikuler',
                'tagihan' => fn ($q) => $q->withSum('transaksi as terbayar', 'alokasi_transaksi.nominal'),
            ])
            ->orderBy(Siswa::select('nama')->whereColumn('siswa.id', 'pendaftaran.siswa_id'))
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Pendaftaran $p) => $this->baris($p));

        return Inertia::render('les-ekskul/tagihan/index', [
            'pendaftaran' => $pendaftaran,
            'bulan' => $this->bulanTagihan($tahunAjaran),
            'kelas' => Kelas::where('tahun_ajaran_id', $tahunAjaran->id)->orderBy('nama_kelas')->get()
                ->map(fn (Kelas $k) => ['id' => $k->id, 'nama' => $k->nama_lengkap]),
            'ringkasan' => $this->ringkasan(),
            'filter' => $filter,
        ]);
    }

    // Bayar di muka: tambah satu tagihan bulan berikutnya untuk pendaftaran ini (FR-9, Catatan v1.8)
    public function bayarDiMuka(Request $request, TagihanService $service): RedirectResponse
    {
        $data = $request->validate(['pendaftaran_id' => ['required', 'integer', 'exists:pendaftaran,id']]);

        $tagihan = $service->buatBulanBerikutnya(Pendaftaran::findOrFail($data['pendaftaran_id']));

        Inertia::flash('toast', $tagihan
            ? ['type' => 'success', 'message' => 'Tagihan '.$tagihan->bulan_tagihan->locale('id')->translatedFormat('F Y').' ditambahkan.']
            : ['type' => 'warning', 'message' => 'Tidak ada lagi bulan yang ditagih untuk kegiatan ini di tahun ajaran ini.']);
        return back();
    }

    private function baris(Pendaftaran $pendaftaran): array
    {
        $awalBulanIni = today()->startOfMonth();

        return [
            'id' => $pendaftaran->id,
            'siswa' => ['id' => $pendaftaran->siswa->id, 'nama' => $pendaftaran->siswa->nama],
            'kelas' => $pendaftaran->siswa->kelasTahunAktif?->kelas->nama_lengkap,
            'kegiatan' => $pendaftaran->nama_kegiatan,
            'status' => $pendaftaran->status,
            'bulan' => $pendaftaran->tagihan->mapWithKeys(fn (Tagihan $t) => [$t->bulan_tagihan->format('Y-m') => [
                'status' => $t->status,
                'harus_dibayar' => $t->harus_dibayar,
                'sisa' => $t->harus_dibayar - (int) $t->terbayar,
            ]]),
            'tunggakan' => $pendaftaran->tagihan
                ->filter(fn (Tagihan $t) => $t->status === StatusTagihan::Belum && $t->bulan_tagihan->lt($awalBulanIni))
                ->sum(fn (Tagihan $t) => $t->harus_dibayar - (int) $t->terbayar),
        ];
    }

    // Kolom bulan: Agustus–Mei tanpa bulan yang tidak ditagih (config les_ekskul)
    private function bulanTagihan(TahunAjaran $tahunAjaran): array
    {
        $bulan = $tahunAjaran->tanggal_mulai->startOfMonth();
        $hasil = [];

        while ($bulan->lte($tahunAjaran->tanggal_selesai)) {
            if (! in_array($bulan->month, config('les_ekskul.bulan_tidak_ditagih'), true)) {
                $hasil[] = $bulan->format('Y-m');
            }
            $bulan = $bulan->addMonth();
        }

        return $hasil;
    }

    // Kartu ringkasan di atas tabel
    private function ringkasan(): array
    {
        $tunggakan = Tagihan::tunggakan()
            ->with('pendaftaran:id,siswa_id')
            ->withSum('transaksi as terbayar', 'alokasi_transaksi.nominal')
            ->get();

        $bulanIni = Tagihan::where('bulan_tagihan', today()->startOfMonth()->toDateString())
            ->where('status', '!=', StatusTagihan::Dibatalkan);

        return [
            'total_tunggakan' => $tunggakan->sum(fn (Tagihan $t) => $t->harus_dibayar - (int) $t->terbayar),
            'siswa_menunggak' => $tunggakan->pluck('pendaftaran.siswa_id')->unique()->count(),
            'bulan_ini_lunas' => (clone $bulanIni)->where('status', StatusTagihan::Lunas)->count(),
            'bulan_ini_total' => $bulanIni->count(),
        ];
    }
}
