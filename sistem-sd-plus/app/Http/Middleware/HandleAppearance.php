<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class HandleAppearance
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Bawaan mode terang (PRD #56); pengguna tetap bisa memilih Gelap/Sistem di Pengaturan > Tampilan
        View::share('appearance', $request->cookie('appearance') ?? 'light');

        return $next($request);
    }
}
