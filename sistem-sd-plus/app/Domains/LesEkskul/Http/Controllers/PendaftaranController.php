<?php

namespace App\Domains\LesEkskul\Http\Controllers;

use App\Domains\LesEkskul\Enums\TipeKegiatan;
use App\Domains\LesEkskul\Http\Requests\DaftarkanSiswaRequest;
use App\Domains\LesEkskul\Models\Ekstrakurikuler;
use App\Domains\LesEkskul\Models\LesTambahan;
use App\Domains\LesEkskul\Models\Pendaftaran;
use App\Domains\LesEkskul\Services\PendaftaranService;
use App\Domains\LesEkskul\Services\PerubahanPendaftaranService;
use App\Http\Controllers\Controller;
use App\Models\Siswa;
use App\Models\SiswaKelas;
use App\Models\TahunAjaran;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PendaftaranController extends Controller
{
    public function __construct(private PendaftaranService $service, private PerubahanPendaftaranService $perubahan) {}

    // Kelola Pendaftaran: daftar pendaftaran tahun ajaran aktif + laporan siswa yang belum ikut ekskul (FR-33)
    public function index(Request $request): Response
    {
        $tahunAjaran = TahunAjaran::aktif()->firstOrFail();
        $filter = $request->only('nama', 'kegiatan', 'status');

        $ekskul = Ekstrakurikuler::where('tahun_ajaran_id', $tahunAjaran->id)
            ->kegiatanInduk()
            ->where('wajib_otomatis', false)
            ->with('kelasParalel')
            ->orderBy('nama')
            ->get();

        $pendaftaran = Pendaftaran::query()
            ->where(fn (Builder $q) => $q
                ->whereHas('lesTambahan', fn (Builder $q) => $q->where('tahun_ajaran_id', $tahunAjaran->id))
                ->orWhereHas('ekstrakurikuler', fn (Builder $q) => $q->where('tahun_ajaran_id', $tahunAjaran->id)))
            ->with(['siswa.kelasTahunAktif.kelas', 'siswa.orangTua', 'lesTambahan.kelas', 'ekstrakurikuler.kelasParalel'])
            ->when($filter['nama'] ?? null, fn (Builder $q, string $nama) => $q
                ->whereHas('siswa', fn (Builder $q) => $q->where('nama', 'ilike', "%{$nama}%")))
            ->when($filter['kegiatan'] ?? null, fn (Builder $q, string $kegiatan) => match ($kegiatan) {
                'les' => $q->whereNotNull('les_tambahan_id'),
                'belum_assign' => $q->whereHas('ekstrakurikuler', fn (Builder $q) => $q->whereNull('parent_ekstrakurikuler_id')->has('kelasParalel')),
                default => $q->whereIn('ekstrakurikuler_id', Ekstrakurikuler::where('id', $kegiatan)
                    ->orWhere('parent_ekstrakurikuler_id', $kegiatan)->select('id')),
            })
            ->when($filter['status'] ?? null, fn (Builder $q, string $status) => $q->where('status', $status))
            ->orderBy(Siswa::select('nama')->whereColumn('siswa.id', 'pendaftaran.siswa_id'))
            ->orderBy('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Pendaftaran $p) => $this->baris($p));

        return Inertia::render('les-ekskul/pendaftaran/index', [
            'pendaftaran' => $pendaftaran,
            'filter' => $filter,
            'kegiatan' => $ekskul->map(fn (Ekstrakurikuler $e) => [
                'id' => $e->id,
                'nama' => $e->nama,
                'biaya' => $e->biaya,
                'jenjang_min' => $e->jenjang_min,
                'jenjang_max' => $e->jenjang_max,
            ]),
            // Pilihan kelas paralel per kegiatan induk, untuk dialog Assign (FR-27)
            'kelasParalel' => $ekskul->filter(fn (Ekstrakurikuler $e) => $e->kelasParalel->isNotEmpty())
                ->mapWithKeys(fn (Ekstrakurikuler $e) => [$e->id => $e->kelasParalel->sortBy('nama')->values()
                    ->map(fn (Ekstrakurikuler $k) => [
                        'id' => $k->id,
                        'nama' => $k->nama,
                        'kuota' => $k->kuota,
                        'terisi' => $this->service->terisi($k),
                    ])]),
            'belumMemenuhi' => $this->service->siswaBelumMemenuhiKewajiban($tahunAjaran)->map(fn (array $b) => [
                'siswa_kelas_id' => $b['siswa_kelas']->id,
                'siswa' => ['id' => $b['siswa']->id, 'nama' => $b['siswa']->nama],
                'kelas' => $b['kelas']->nama_lengkap,
                'kurang' => $b['kurang'],
            ]),
            // Siswa berkondisi khusus yang dibebaskan dari kewajiban ekskul tahun ini (#30)
            'dibebaskan' => SiswaKelas::where('tahun_ajaran_id', $tahunAjaran->id)
                ->whereNotNull('alasan_bebas_ekskul')
                ->with('siswa', 'kelas')
                ->get()
                ->sortBy(fn (SiswaKelas $sk) => $sk->siswa->nama)
                ->values()
                ->map(fn (SiswaKelas $sk) => [
                    'siswa_kelas_id' => $sk->id,
                    'siswa' => ['id' => $sk->siswa->id, 'nama' => $sk->siswa->nama],
                    'kelas' => $sk->kelas->nama_lengkap,
                    'alasan' => $sk->alasan_bebas_ekskul,
                ]),
            'saranTanggal' => $this->perubahan->saranTanggal($tahunAjaran->load('semester')),
        ]);
    }

    // Tambah Pendaftaran (FR-6): cari siswa, lalu pilih kegiatan yang boleh diikutinya
    public function create(Request $request): Response
    {
        $siswa = $request->integer('siswa') ? Siswa::with('kelasTahunAktif.kelas')->find($request->integer('siswa')) : null;

        return Inertia::render('les-ekskul/pendaftaran/create', [
            'cari' => $request->string('cari')->toString(),
            'hasilCari' => $request->filled('cari')
                ? Siswa::where('nama', 'ilike', '%'.$request->string('cari').'%')
                    ->with('kelasTahunAktif.kelas')->orderBy('nama')->limit(10)->get()
                    ->map(fn (Siswa $s) => $this->ringkasSiswa($s))
                : [],
            'siswa' => $siswa ? $this->ringkasSiswa($siswa) : null,
            'terdaftar' => $siswa
                ? $siswa->pendaftaran()->aktif()->with('lesTambahan.kelas', 'ekstrakurikuler')->get()
                    ->map(fn (Pendaftaran $p) => ['id' => $p->id, 'kegiatan' => $p->nama_kegiatan, 'tanggal_daftar' => $p->tanggal_daftar->toDateString()])
                : [],
            'pilihan' => $siswa
                ? $this->service->pilihanKegiatan($siswa)->map(fn (LesTambahan|Ekstrakurikuler $k) => $this->pilihan($siswa, $k))->values()
                : [],
            'hariIni' => today()->toDateString(),
        ]);
    }

    public function store(DaftarkanSiswaRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $siswa = Siswa::findOrFail($data['siswa_id']);
        $kegiatan = $data['jenis'] === TipeKegiatan::LesTambahan->value
            ? LesTambahan::findOrFail($data['kegiatan_id'])
            : Ekstrakurikuler::findOrFail($data['kegiatan_id']);

        $pendaftaran = $this->service->daftar($siswa, $kegiatan, $data['tanggal_daftar'], $request->boolean('abaikan_kuota'));
        $jumlahTagihan = $pendaftaran->tagihan()->count();

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$siswa->nama} didaftarkan ke {$pendaftaran->nama_kegiatan}."
            .($jumlahTagihan > 0 ? " {$jumlahTagihan} tagihan dibuat." : '')]);

        // Tetap di halaman siswa ini supaya TU bisa langsung menambah kegiatan lain
        return to_route('les-ekskul.pendaftaran.create', ['siswa' => $siswa->id]);
    }

    public function assign(Request $request, Pendaftaran $pendaftaran): RedirectResponse
    {
        $data = $request->validate([
            'kelas_paralel_id' => ['required', 'integer', 'exists:ekstrakurikuler,id'],
            'abaikan_kuota' => ['boolean'],
        ], ['kelas_paralel_id.required' => 'Pilih kelas paralel.']);

        $tujuan = Ekstrakurikuler::findOrFail($data['kelas_paralel_id']);
        $this->service->assignKelasParalel($pendaftaran, $tujuan, $request->boolean('abaikan_kuota'));

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$pendaftaran->siswa->nama} di-assign ke {$tujuan->nama}."]);

        return back();
    }

    // FR-10: berhentikan les/ekskul; tagihan yang tidak diikuti lagi dibatalkan, yang sudah dibayar direfund (FR-22)
    public function berhenti(Request $request, Pendaftaran $pendaftaran): RedirectResponse
    {
        $data = $this->validasiPerubahan($request);
        $hasil = $this->perubahan->berhenti($pendaftaran, $data['tanggal'], $data['alasan_berhenti'] ?? null, $request->boolean('konfirmasi'), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$pendaftaran->siswa->nama} berhenti dari {$pendaftaran->nama_kegiatan}."
            .$this->dampak($hasil['dibatalkan'], 0, $hasil['refund'])]);

        return back();
    }

    // FR-11: pindah ekskul; uang yang sudah dibayar menjadi potongan tagihan kegiatan baru (#29)
    public function pindah(Request $request, Pendaftaran $pendaftaran): RedirectResponse
    {
        $data = $this->validasiPerubahan($request, ['ekstrakurikuler_id' => ['required', 'integer', 'exists:ekstrakurikuler,id']]);
        $tujuan = Ekstrakurikuler::findOrFail($data['ekstrakurikuler_id']);

        $hasil = $this->perubahan->pindah(
            $pendaftaran,
            $tujuan,
            $data['tanggal'],
            $data['alasan_berhenti'] ?? null,
            $request->boolean('konfirmasi'),
            $request->boolean('abaikan_kuota'),
            $request->user(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$pendaftaran->siswa->nama} pindah ke {$tujuan->nama} mulai "
            .$hasil['baru']->tanggal_daftar->locale('id')->translatedFormat('j F Y').'.'
            .$this->dampak($hasil['dibatalkan'], $hasil['dialihkan'], $hasil['refund'])]);

        return back();
    }

    // #30: bebaskan siswa berkondisi khusus dari kewajiban ekskul di tahun ajaran ini
    public function bebaskan(Request $request, SiswaKelas $siswaKelas): RedirectResponse
    {
        $data = $request->validate(['alasan' => ['required', 'string', 'max:255']], ['alasan.required' => 'Alasan wajib diisi.']);
        $siswaKelas->update(['alasan_bebas_ekskul' => trim($data['alasan'])]);

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$siswaKelas->siswa->nama} dibebaskan dari kewajiban ekskul tahun ini."]);

        return back();
    }

    public function cabutBebas(SiswaKelas $siswaKelas): RedirectResponse
    {
        $siswaKelas->update(['alasan_bebas_ekskul' => null]);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Pembebasan {$siswaKelas->siswa->nama} dicabut; kembali wajib ikut ekskul."]);

        return back();
    }

    private function validasiPerubahan(Request $request, array $tambahan = []): array
    {
        return $request->validate([
            'tanggal' => ['required', 'date'],
            'alasan_berhenti' => ['nullable', 'string', 'max:500'],
            'konfirmasi' => ['boolean'],
            'abaikan_kuota' => ['boolean'],
            ...$tambahan,
        ], [
            'tanggal.required' => 'Tanggal efektif wajib diisi.',
            'ekstrakurikuler_id.required' => 'Pilih ekskul tujuan.',
        ]);
    }

    // Ringkasan dampak keuangan untuk toast, mis. " 2 tagihan dibatalkan, refund Rp160.000 dicatat."
    private function dampak(int $dibatalkan, int $dialihkan, int $refund): string
    {
        $bagian = array_filter([
            $dibatalkan > 0 ? "{$dibatalkan} tagihan dibatalkan" : null,
            $dialihkan > 0 ? 'Rp'.number_format($dialihkan, 0, ',', '.').' dialihkan ke kegiatan baru' : null,
            $refund > 0 ? 'refund Rp'.number_format($refund, 0, ',', '.').' dicatat' : null,
        ]);

        return $bagian ? ' '.ucfirst(implode(', ', $bagian)).'.' : '';
    }

    private function baris(Pendaftaran $p): array
    {
        $ekskul = $p->ekstrakurikuler;

        return [
            'id' => $p->id,
            'siswa' => ['id' => $p->siswa->id, 'nama' => $p->siswa->nama],
            'kelas' => $p->siswa->kelasTahunAktif?->kelas->nama_lengkap,
            'jenjang' => $p->siswa->kelasTahunAktif?->kelas->jenjang,
            'kegiatan' => $p->nama_kegiatan,
            'jenis' => $p->adalahLesTambahan() ? TipeKegiatan::LesTambahan : TipeKegiatan::Ekstrakurikuler,
            'status' => $p->status,
            'tanggal_daftar' => $p->tanggal_daftar->toDateString(),
            'tanggal_efektif_berhenti' => $p->tanggal_efektif_berhenti?->toDateString(),
            'alasan_berhenti' => $p->alasan_berhenti,
            'biaya' => $p->kegiatan()->biaya,
            'orang_tua' => $p->siswa->orangTua->pluck('no_hp')->filter()->join(', ') ?: null,
            // id kegiatan induk (untuk mengambil pilihan kelas paralel) dan penanda belum di-assign
            'induk_id' => $ekskul ? ($ekskul->parent_ekstrakurikuler_id ?? $ekskul->id) : null,
            'perlu_assign' => $ekskul !== null && ! $ekskul->adalahKelasParalel() && $ekskul->kelasParalel->isNotEmpty(),
        ];
    }

    private function pilihan(Siswa $siswa, LesTambahan|Ekstrakurikuler $kegiatan): array
    {
        $hasil = $this->service->periksa($siswa, $kegiatan);
        $ekskul = $kegiatan instanceof Ekstrakurikuler;
        $jenjang = $siswa->kelasTahunAktif->kelas->jenjang;

        return [
            'jenis' => $ekskul ? TipeKegiatan::Ekstrakurikuler : TipeKegiatan::LesTambahan,
            'id' => $kegiatan->id,
            'nama' => $ekskul ? $kegiatan->nama : 'Les Tambahan '.$kegiatan->kelas->nama_lengkap,
            'biaya' => $kegiatan->biaya,
            'jadwal' => $ekskul
                ? $this->service->jadwalUntuk($kegiatan, $jenjang)
                    ->map(fn ($j) => $this->service->teksJadwal($j).' · '.$j->lokasi)->values()
                : [],
            'kuota' => $ekskul ? $kegiatan->kuota : null,
            'terisi' => $ekskul ? $this->service->terisi($kegiatan) : null,
            'punya_kelas_paralel' => $ekskul && $kegiatan->kelasParalel->isNotEmpty(),
            'error' => $hasil['error'],
            'peringatan' => $hasil['peringatan'],
        ];
    }

    private function ringkasSiswa(Siswa $siswa): array
    {
        return ['id' => $siswa->id, 'nama' => $siswa->nama, 'kelas' => $siswa->kelasTahunAktif?->kelas->nama_lengkap];
    }
}
