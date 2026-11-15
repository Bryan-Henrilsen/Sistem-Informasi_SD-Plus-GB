<?php

namespace App\Domains\LesEkskul\Http\Controllers;

use App\Domains\LesEkskul\Enums\JenisPengajar;
use App\Domains\LesEkskul\Enums\KategoriPendamping;
use App\Domains\LesEkskul\Models\Ekstrakurikuler;
use App\Domains\LesEkskul\Models\PengajarEkskul;
use App\Domains\LesEkskul\Services\PengajarEkskulService;
use App\Http\Controllers\Controller;
use App\Models\TahunAjaran;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

// Halaman Pengajar Ekskul: data pengajar, penugasan per kegiatan/kelas, pendamping, dan cara hitung honor (#33)
class PengajarEkskulController extends Controller
{
    public function __construct(private PengajarEkskulService $service) {}

    public function index(Request $request): Response
    {
        $tahunAjaran = TahunAjaran::aktif()->firstOrFail();

        // Kegiatan wajib otomatis dan honor flat (Olimpiade) tidak memakai skema persen, jadi tidak perlu pengajar di sini
        $kegiatan = Ekstrakurikuler::where('tahun_ajaran_id', $tahunAjaran->id)
            ->kegiatanInduk()
            ->where('wajib_otomatis', false)
            ->where('honor_flat', false)
            ->with(['pengajar', 'pendamping', 'kelasParalel' => fn ($q) => $q->orderBy('nama')->with('pengajar', 'pendamping')])
            ->orderBy('nama')
            ->get();

        return Inertia::render('les-ekskul/honor/pengajar', [
            'pengajar' => PengajarEkskul::with('user')->withCount('ekstrakurikuler')->orderBy('nama')->get()
                ->map(fn (PengajarEkskul $p) => [
                    'id' => $p->id,
                    'nama' => $p->nama,
                    'jenis' => $p->jenis,
                    'user_id' => $p->user_id,
                    'no_hp' => $p->no_hp,
                    'status_aktif' => $p->status_aktif,
                    'jumlah_kegiatan' => $p->ekstrakurikuler_count,
                ]),
            'kegiatan' => $kegiatan->map(fn (Ekstrakurikuler $e) => [
                'id' => $e->id,
                'nama' => $e->nama,
                'kategori' => $e->kategori_pendamping,
                'per_kelas' => $e->honor_per_kelas_paralel,
                'punya_paralel' => $e->kelasParalel->isNotEmpty(),
                // Baris tempat pengajar dicatat: kelas paralel bila ada, selain itu kegiatan induknya
                'baris' => ($e->kelasParalel->isNotEmpty() ? $e->kelasParalel : collect([$e]))
                    ->map(fn (Ekstrakurikuler $b) => ['id' => $b->id, 'nama' => $b->nama, 'pengajar_ids' => $b->pengajar->pluck('id')])
                    ->values(),
                // Unit honor tempat pendamping dicatat: induk bila digabung, tiap kelas bila per kelas
                'unit' => $this->service->unitHonor($e)
                    ->map(fn (Ekstrakurikuler $u) => ['id' => $u->id, 'nama' => $u->nama, 'pendamping_ids' => $u->pendamping->pluck('id')])
                    ->values(),
                'masalah' => $this->service->masalahKegiatan($e),
            ]),
            'masalahLes' => $this->service->masalahLes($tahunAjaran),
            'orang' => $this->orangSekolah(),
            'bisaAturPendamping' => $request->user()->hasRole('kepala_sekolah'),
            'bisaUbah' => $request->user()->hasAnyRole(['tu', 'kepala_sekolah']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->service->simpanPengajar($this->validasiPengajar($request));

        return $this->kembali('Pengajar ditambahkan.');
    }

    public function update(Request $request, PengajarEkskul $pengajarEkskul): RedirectResponse
    {
        $this->service->simpanPengajar($this->validasiPengajar($request), $pengajarEkskul);

        return $this->kembali('Data pengajar diperbarui.');
    }

    public function aturMode(Request $request, Ekstrakurikuler $ekstrakurikuler): RedirectResponse
    {
        $data = $request->validate(['per_kelas' => ['required', 'boolean']]);
        $this->service->aturModeHonor($ekstrakurikuler, $data['per_kelas']);

        return $this->kembali("Honor {$ekstrakurikuler->nama} dihitung ".($data['per_kelas'] ? 'per kelas paralel.' : 'digabung seluruh kelas.'));
    }

    public function aturKategori(Request $request, Ekstrakurikuler $ekstrakurikuler): RedirectResponse
    {
        $data = $request->validate(['kategori' => ['nullable', Rule::enum(KategoriPendamping::class)]]);
        $this->service->aturKategori($ekstrakurikuler, $data['kategori'] ?? null);

        return $this->kembali("Kategori {$ekstrakurikuler->nama} disimpan.");
    }

    public function aturPengajar(Request $request, Ekstrakurikuler $ekstrakurikuler): RedirectResponse
    {
        $data = $request->validate(['pengajar_ids' => ['array'], 'pengajar_ids.*' => ['integer', 'exists:pengajar_ekskul,id']]);
        $this->service->aturPengajar($ekstrakurikuler, $data['pengajar_ids'] ?? []);

        return $this->kembali("Pengajar {$ekstrakurikuler->nama} disimpan.");
    }

    public function aturPendamping(Request $request, Ekstrakurikuler $ekstrakurikuler): RedirectResponse
    {
        $data = $request->validate(['user_ids' => ['array'], 'user_ids.*' => ['integer', 'exists:users,id']]);
        $this->service->aturPendamping($ekstrakurikuler, $data['user_ids'] ?? []);

        return $this->kembali("Pendamping {$ekstrakurikuler->nama} disimpan.");
    }

    // Guru dan staf sekolah (punya role selain orang tua): calon pengajar internal & pendamping
    private function orangSekolah(): array
    {
        return User::role(['guru', 'tu', 'bendahara', 'kepala_sekolah', 'waka_kurikulum'])
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $u) => ['id' => $u->id, 'nama' => $u->name])
            ->all();
    }

    private function validasiPengajar(Request $request): array
    {
        return $request->validate([
            'jenis' => ['required', Rule::enum(JenisPengajar::class)],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'nama' => ['nullable', 'string', 'max:255'],
            'no_hp' => ['nullable', 'string', 'max:20'],
            'status_aktif' => ['boolean'],
        ]);
    }

    private function kembali(string $pesan): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $pesan]);

        return back();
    }
}
