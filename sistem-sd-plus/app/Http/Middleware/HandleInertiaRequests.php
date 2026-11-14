<?php

namespace App\Http\Middleware;

use App\Domains\LesEkskul\Services\RingkasanTugasService;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
                'roles' => $request->user()?->getRoleNames() ?? [], // untuk menampilkan menu & tombol sesuai role
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            // Angka badge menu Les & Ekskul (yang menunggu tindakan role pengguna); null untuk role non-keuangan
            'badge' => fn () => $request->user()?->hasAnyRole(RingkasanTugasService::ROLE_KEUANGAN)
                ? app(RingkasanTugasService::class)->badge($request->user())
                : null,
        ];
    }
}
