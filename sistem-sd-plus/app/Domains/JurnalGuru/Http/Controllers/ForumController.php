<?php

namespace App\Domains\JurnalGuru\Http\Controllers;

use App\Domains\JurnalGuru\Models\ForumPesan;
use App\Domains\JurnalGuru\Services\ForumService;
use App\Http\Controllers\Controller;
use App\Models\Guru;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

// Forum Guru (PRD B.2 FR-2–FR-4): guru langsung masuk ke percakapannya, Kepala Sekolah melihat daftar semua guru
class ForumController extends Controller
{
    public function __construct(private ForumService $service) {}

    public function index(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if (! $user->hasRole('kepala_sekolah')) {
            $guru = $this->service->guruMilik($user) ?? abort(403, 'Akun ini belum terhubung ke data guru.');

            return redirect()->route('jurnal-guru.forum.show', $guru);
        }

        return Inertia::render('jurnal-guru/forum/index', [
            'thread' => $this->service->daftarThread(),
        ]);
    }

    public function show(Request $request, Guru $guru): Response
    {
        $user = $request->user();
        abort_unless($this->service->bolehBuka($user, $guru), 403);

        $this->service->tandaiDibaca($guru, $user);
        $guru->load('user:id,name');

        return Inertia::render('jurnal-guru/forum/thread', [
            'guru' => ['id' => $guru->id, 'nama' => $guru->user?->name ?? $guru->kode_guru, 'aktif' => $guru->status_aktif],
            'pesan' => $this->service->pesan($guru, $user),
            'sebagaiKepsek' => $user->hasRole('kepala_sekolah') && $guru->user_id !== $user->id,
            'panjangMaks' => ForumService::PANJANG_MAKS,
            'menitBolehHapus' => ForumPesan::MENIT_BOLEH_HAPUS,
        ]);
    }

    public function store(Request $request, Guru $guru): RedirectResponse
    {
        abort_unless($this->service->bolehBuka($request->user(), $guru), 403);
        $data = $request->validate(['isi' => ['required', 'string']], ['isi.required' => 'Pesan tidak boleh kosong.']);

        $this->service->kirim($guru, $request->user(), $data['isi']);

        return back();
    }

    public function destroy(Request $request, ForumPesan $forumPesan): RedirectResponse
    {
        abort_unless($this->service->bolehBuka($request->user(), $forumPesan->guru), 403);
        $this->service->hapus($forumPesan, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Pesan dihapus.']);

        return back();
    }
}
