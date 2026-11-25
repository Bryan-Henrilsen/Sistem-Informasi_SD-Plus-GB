<?php

namespace App\Domains\JurnalGuru\Http\Controllers;

use App\Domains\JurnalGuru\Services\ForumService;
use App\Domains\JurnalGuru\Services\JadwalSayaService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

// Jadwal Saya (PRD B.2 FR-1): jadwal mengajar pribadi guru, read-only
class JadwalSayaController extends Controller
{
    public function __invoke(Request $request, ForumService $forum, JadwalSayaService $jadwal): Response
    {
        $guru = $forum->guruMilik($request->user()) ?? abort(403, 'Akun ini belum terhubung ke data guru.');

        return Inertia::render('jurnal-guru/jadwal-saya', $jadwal->untuk($guru));
    }
}
