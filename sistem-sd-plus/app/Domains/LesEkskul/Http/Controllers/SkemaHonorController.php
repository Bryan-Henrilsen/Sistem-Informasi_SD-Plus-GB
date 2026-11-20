<?php

namespace App\Domains\LesEkskul\Http\Controllers;

use App\Domains\LesEkskul\Enums\JenisPengajar;
use App\Domains\LesEkskul\Enums\PeranHonor;
use App\Domains\LesEkskul\Enums\TipeKegiatan;
use App\Domains\LesEkskul\Models\HonorFlatJenis;
use App\Domains\LesEkskul\Models\PenerimaPeranHonor;
use App\Domains\LesEkskul\Services\PengaturanHonorService;
use App\Http\Controllers\Controller;
use App\Models\TahunAjaran;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

// Halaman Skema Honor: persen per skema (FR-17), penerima peran pengelola (FR-18), honor flat (FR-25b)
class SkemaHonorController extends Controller
{
    public function __construct(private PengaturanHonorService $service) {}

    public function index(Request $request): Response
    {
        $tahunAjaran = TahunAjaran::aktif()->firstOrFail();

        return Inertia::render('les-ekskul/honor/skema', [
            'skema' => $this->service->skemaBerlaku()->map(fn (array $s) => [
                'tipe' => $s['tipe'],
                'jenis' => $s['jenis'],
                'berlaku_sejak' => $s['skema']?->berlaku_sejak->toDateString(),
                'riwayat' => $s['riwayat'],
                'persen' => collect(PengaturanHonorService::KOLOM_PERSEN)
                    ->mapWithKeys(fn (string $k) => [$k => $s['skema']?->{$k} === null ? null : (float) $s['skema']->{$k}]),
            ]),
            'peran' => collect(PengaturanHonorService::PERAN_PENGELOLA)->map(fn (PeranHonor $p) => [
                'peran' => $p,
                'label' => PengaturanHonorService::labelPeran($p),
                'user_id' => PenerimaPeranHonor::pemegang($p, today())?->id,
            ]),
            'honorFlat' => HonorFlatJenis::orderBy('id')->get()->map(function (HonorFlatJenis $j) use ($tahunAjaran) {
                $pengaturan = $this->service->honorFlatBerlaku($j, $tahunAjaran);

                return [
                    'id' => $j->id,
                    'kode' => $j->kode,
                    'nama' => $j->nama,
                    'nominal' => $pengaturan?->nominal_per_guru,
                    'berlaku_sejak' => $pengaturan?->berlaku_sejak->toDateString(),
                    'penerima' => $pengaturan
                        ? $pengaturan->penerima->map(fn (User $u) => ['user_id' => $u->id, 'jumlah' => (int) $u->pivot->jumlah])->values()
                        : [],
                ];
            }),
            'orang' => $this->orang(),
            'bisaUbah' => $request->user()->hasRole('kepala_sekolah'),
            'bisaTambahTanpaAkun' => $request->user()->hasAnyRole(['tu', 'kepala_sekolah']),
        ]);
    }

    public function simpanSkema(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'tipe' => ['required', Rule::enum(TipeKegiatan::class)],
            'jenis' => ['nullable', Rule::enum(JenisPengajar::class)],
            'persen' => ['required', 'array'],
            'persen.*' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $tipe = TipeKegiatan::from($data['tipe']);
        $jenis = $tipe === TipeKegiatan::Ekstrakurikuler ? JenisPengajar::from($data['jenis'] ?? 'internal') : null;
        $this->service->simpanSkema($tipe, $jenis, $data['persen']);

        return $this->kembali('Skema honor disimpan, berlaku mulai hari ini. Honor periode yang sudah dihitung tidak berubah.');
    }

    public function simpanPeran(Request $request): RedirectResponse
    {
        $data = $request->validate(['penerima' => ['required', 'array'], 'penerima.*' => ['nullable', 'integer', 'exists:users,id']]);
        $this->service->simpanPenerimaPeran($data['penerima'], $request->user());

        return $this->kembali('Penerima porsi pengelola disimpan.');
    }

    public function simpanHonorFlat(Request $request, HonorFlatJenis $honorFlatJenis): RedirectResponse
    {
        $data = $request->validate([
            'nominal' => ['required', 'integer', 'min:0'],
            'penerima' => ['array'],
            'penerima.*.user_id' => ['required', 'integer', 'exists:users,id'],
            'penerima.*.jumlah' => ['required', 'integer', 'min:1'],
        ], ['nominal.required' => 'Nominal wajib diisi.']);

        $penerima = collect($data['penerima'] ?? [])->mapWithKeys(fn (array $p) => [$p['user_id'] => $p['jumlah']])->all();
        $this->service->simpanHonorFlat($honorFlatJenis, (int) $data['nominal'], $penerima, $request->user());

        return $this->kembali("Honor {$honorFlatJenis->nama} disimpan.");
    }

    public function tambahTanpaAkun(Request $request): RedirectResponse
    {
        $data = $request->validate(['nama' => ['required', 'string', 'max:255']], ['nama.required' => 'Nama wajib diisi.']);
        $user = $this->service->tambahPenerimaTanpaAkun($data['nama']);

        return $this->kembali("{$user->name} ditambahkan sebagai penerima tanpa akun login.");
    }

    // Guru, staf, dan penerima tanpa akun: pilihan penerima peran & honor flat (dipakai juga di Rekap Honor)
    private function orang(): array
    {
        return $this->service->orangPenerima();
    }

    private function kembali(string $pesan): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $pesan]);

        return back();
    }
}
