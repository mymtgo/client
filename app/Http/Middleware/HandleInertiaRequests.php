<?php

namespace App\Http\Middleware;

use App\Models\Account;
use App\Models\AppSetting;
use App\Models\LogCursor;
use App\Models\MtgoMatch;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Middleware;
use Native\Desktop\Facades\Settings;

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
                'watcherRunning' => (bool) Settings::get('watcher_active', true),
                'lastIngestAt' => LogCursor::max('updated_at'),
                'lastIngestAtHuman' => ($ts = LogCursor::max('updated_at')) ? Carbon::parse($ts)->setTimezone(AppSetting::displayTimezone())->diffForHumans() : null,
                'pendingMatchCount' => MtgoMatch::submittable()->count(),
            ],
            'debugMode' => fn () => (bool) Settings::get('debug_mode'),
            'activeAccount' => fn () => Account::active()->first()?->username,
            'accounts' => fn () => Account::tracked()->orderBy('username')->get(['id', 'username', 'active']),
            'availableUpdate' => fn () => Cache::get('available_update'),
        ];
    }
}
