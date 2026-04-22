<?php

namespace App\Http\Middleware;

use App\Facades\AppSettings;
use App\Models\Account;
use App\Models\LogCursor;
use App\Models\MtgoMatch;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Middleware;

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
            'flash' => fn () => [
                'error' => $request->session()->get('error'),
            ],
            'status' => fn () => [
                'watcherRunning' => AppSettings::isWatcherActive(),
                'lastIngestAt' => LogCursor::max('updated_at'),
                'lastIngestAtHuman' => ($ts = LogCursor::max('updated_at')) ? Carbon::parse($ts)->toLocal()->diffForHumans() : null,
                'pendingMatchCount' => MtgoMatch::submittable()->count(),
            ],
            'debugMode' => fn () => AppSettings::isDebugMode(),
            'activeAccount' => fn () => Account::active()->first()?->username,
            'accounts' => fn () => Account::tracked()->orderBy('username')->get(['id', 'username', 'active']),
            'availableUpdate' => fn () => Cache::get('available_update'),
            'support' => [
                'discordInviteUrl' => config('support.discord_invite_url'),
            ],
        ];
    }
}
