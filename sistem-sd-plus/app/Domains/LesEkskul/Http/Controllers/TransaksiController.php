<?php

namespace App\Domains\LesEkskul\Http\Controllers;

use App\Domains\LesEkskul\Enums\MetodeTransaksi;
use App\Domains\LesEkskul\Enums\StatusApprovalBendahara;
use App\Domains\LesEkskul\Enums\StatusTransaksi;
use App\Domains\LesEkskul\Enums\SumberInput;
use App\Domains\LesEkskul\Http\Requests\CatatTransaksiRequest;
use App\Domains\LesEkskul\Models\Pendaftaran;
use App\Domains\LesEkskul\Models\Tagihan;
use App\Domains\LesEkskul\Models\Transaksi;
use App\Domains\LesEkskul\Services\TagihanService;
use App\Domains\LesEkskul\Services\TransaksiService;
use App\Http\Controllers\Controller;
use App\Models\Siswa;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TransaksiController extends Controller
{
    public function __construct(private TransaksiService $service) {}

    // Daftar transaksi terbaru + filter status; tombol verifikasi/setujui/tolak diatur di halaman sesuai role
    public function index(Request $request): Response
    {
        $filter = $request->only('nama', 'status', 'metode');

        $transaksi = Transaksi::query()
            ->with(['siswa', 'diinputOlehUser', 'penyesuaianDana', 'tagihan.pendaftaran.lesTambahan.kelas', 'tagihan.pendaftaran.ekstrakurikuler'])
            ->when($filter['nama'] ?? null, fn (Builder $q, string $nama) => $q
                ->whereHas('siswa', fn (Builder $q) => $q->where('nama', 'ilike', "%{$nama}%")))
            ->when($filter['metode'] ?? null, fn (Builder $q, string $metode) => $q->where('metode', $metode))
            ->when($filter['status'] ?? null, fn (Builder $q, string $status) => match ($status) {
                'menunggu_bendahara' => $q->where('status', StatusTransaksi::Terverifikasi)
                    ->where('status_approval_bendahara', StatusApprovalBendahara::BelumDicek),
                default => $q->where('status', $status),
            })
            ->orderByDesc('tanggal')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Transaksi $t) => [
                'id' => $t->id,
                'tanggal' => $t->tanggal->toDateString(),
                'siswa' => $t->siswa->nama,
                'metode' => $t->metode,
                'nominal' => $t->nominal,
                'status' => $t->status,
                'status_approval_bendahara' => $t->status_approval_bendahara,
                'sumber_input' => $t->sumber_input,
                'catatan_transfer' => $t->catatan_transfer,
                'alasan_ditolak' => $t->alasan_ditolak,
                'diinput_oleh' => $t->diinputOlehUser?->name,
                'alokasi' => $t->tagihan->sortBy('bulan_tagihan')->values()->map(fn (Tagihan $tg) => [
                    'kegiatan' => $tg->pendaftaran->nama_kegiatan,
                    'bulan' => $tg->bulan_tagihan->format('Y-m'),
                    'nominal' => $tg->pivot->nominal,
                ]),
                'refund' => $t->penyesuaianDana->sum('nominal'),
            ]);

        return Inertia::render('les-ekskul/transaksi/index', [
            'transaksi' => $transaksi,
            'filter' => $filter,
            'jumlah' => [
                'pending' => Transaksi::where('status', StatusTransaksi::Pending)->count(),
                'menunggu_bendahara' => Transaksi::where('status', StatusTransaksi::Terverifikasi)
                    ->where('status_approval_bendahara', StatusApprovalBendahara::BelumDicek)->count(),
            ],
        ]);
    }

    // Form Catat Pembayaran: cari siswa, lalu tampilkan tagihan yang masih bisa dibayar
    public function create(Request $request, TagihanService $tagihanService): Response
    {
        $siswa = $request->integer('siswa') ? Siswa::with('kelasTahunAktif.kelas')->find($request->integer('siswa')) : null;

        return Inertia::render('les-ekskul/transaksi/create', [
            'cari' => $request->string('cari')->toString(),
            'hasilCari' => $request->filled('cari')
                ? Siswa::where('nama', 'ilike', '%'.$request->string('cari').'%')
                    ->with('kelasTahunAktif.kelas')->orderBy('nama')->limit(10)->get()
                    ->map(fn (Siswa $s) => $this->ringkasSiswa($s))
                : [],
            'siswa' => $siswa ? $this->ringkasSiswa($siswa) : null,
            'tagihan' => $siswa ? $this->tagihanTerbuka($siswa) : [],
            // Tombol bayar di muka hanya untuk kegiatan yang masih punya bulan berikutnya di semester berjalan
            'pendaftaran' => $siswa
                ? $siswa->pendaftaran()->aktif()->with('lesTambahan.kelas', 'ekstrakurikuler')->get()
                    ->map(fn (Pendaftaran $p) => [
                        'id' => $p->id,
                        'kegiatan' => $p->nama_kegiatan,
                        'bulan' => $tagihanService->bulanBayarDiMukaBerikutnya($p)?->format('Y-m'),
                    ])
                    ->filter(fn (array $p) => $p['bulan'] !== null)
                    ->values()
                : [],
            'hariIni' => today()->toDateString(),
        ]);
    }

    public function store(CatatTransaksiRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $user = $request->user();

        $transaksi = $this->service->catat(
            siswa: Siswa::findOrFail($data['siswa_id']),
            metode: MetodeTransaksi::from($data['metode']),
            nominal: (int) $data['nominal'],
            tagihanIds: array_map('intval', $data['tagihan_ids']),
            oleh: $user,
            sumber: $user->hasRole('tu') ? SumberInput::Tu : SumberInput::Bendahara, // Bendahara saja → FR-8c
            tanggal: $data['tanggal'],
            catatanTransfer: $data['catatan_transfer'] ?? null,
        );

        $refund = $transaksi->penyesuaianDana()->sum('nominal');
        $pesan = 'Pembayaran '.$this->rupiah($transaksi->nominal).' dicatat.'
            .($refund > 0 ? ' Kelebihan '.$this->rupiah((int) $refund).' dicatat sebagai refund.' : '');

        Inertia::flash('toast', ['type' => 'success', 'message' => $pesan]);

        return to_route('les-ekskul.transaksi.index');
    }

    public function verifikasi(Request $request, Transaksi $transaksi): RedirectResponse
    {
        $this->service->verifikasi($transaksi, $request->user());

        return $this->kembali('Transfer diverifikasi.');
    }

    public function setujui(Request $request, Transaksi $transaksi): RedirectResponse
    {
        $this->service->setujui($transaksi, $request->user());

        return $this->kembali('Transfer disetujui.');
    }

    public function tolak(Request $request, Transaksi $transaksi): RedirectResponse
    {
        $data = $request->validate(['alasan' => ['required', 'string', 'max:500']], [
            'alasan.required' => 'Alasan penolakan wajib diisi.',
        ]);

        $this->service->tolak($transaksi, $request->user(), $data['alasan']);

        return $this->kembali('Transfer ditolak, tagihannya kembali belum lunas.');
    }

    private function kembali(string $pesan): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $pesan]);

        return back();
    }

    private function ringkasSiswa(Siswa $siswa): array
    {
        return ['id' => $siswa->id, 'nama' => $siswa->nama, 'kelas' => $siswa->kelasTahunAktif?->kelas->nama_lengkap];
    }

    // Tagihan siswa yang belum lunas dan tidak sedang di order online, urut bulan terlama (sama dengan urutan pembagian uang)
    private function tagihanTerbuka(Siswa $siswa): array
    {
        return Tagihan::whereHas('pendaftaran', fn (Builder $q) => $q->where('siswa_id', $siswa->id))
            ->bisaDibayarOnline()
            ->with('pendaftaran.lesTambahan.kelas', 'pendaftaran.ekstrakurikuler')
            ->withSum('transaksi as terbayar', 'alokasi_transaksi.nominal')
            ->orderBy('bulan_tagihan')
            ->get()
            ->map(fn (Tagihan $t) => [
                'id' => $t->id,
                'pendaftaran_id' => $t->pendaftaran_id,
                'kegiatan' => $t->pendaftaran->nama_kegiatan,
                'bulan' => $t->bulan_tagihan->format('Y-m'),
                'harus_dibayar' => $t->harus_dibayar,
                'sisa' => $t->harus_dibayar - (int) $t->terbayar,
            ])
            ->all();
    }

    private function rupiah(int $nilai): string
    {
        return 'Rp'.number_format($nilai, 0, ',', '.');
    }
}
