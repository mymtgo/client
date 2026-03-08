<?php

namespace App\Http\Middleware;

use App\Models\Account;
use App\Models\LogCursor;
use App\Models\MtgoMatch;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Native\Desktop\Facades\Window;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'status' => fn () => [
                'watcherRunning' => (bool) \Native\Desktop\Facades\Settings::get('watcher_active', true),
                'lastIngestAt' => LogCursor::first()?->updated_at,
                'pendingMatchCount' => MtgoMatch::submittable()->count(),
            ],
            'activeAccount' => fn () => Account::active()->first()?->username,
            'accounts' => fn () => Account::tracked()->orderBy('username')->get(['id', 'username', 'active']),
            'overlayOpen' => fn () => collect(Window::all())->contains('id', 'overlay'),
            'overlayEnabled' => fn () => (bool) \Native\Desktop\Facades\Settings::get('overlay_enabled'),
        ];
    }
}
