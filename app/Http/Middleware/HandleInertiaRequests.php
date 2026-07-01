<?php

namespace App\Http\Middleware;

use App\Facades\AppSettings;
use App\Models\LogCursor;
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
        // NOTE: auth / plan / needsAttentionCount shared props (AppAccount +
        // Outbox) are added in client-ui Task 1, once those models exist.
        return [
            ...parent::share($request),
            'flash' => fn () => [
                'error' => $request->session()->get('error'),
            ],
            'status' => fn () => [
                'watcherRunning' => AppSettings::isWatcherActive(),
                'lastIngestAt' => $ts = LogCursor::max('updated_at'),
                'lastIngestAtHuman' => $ts ? Carbon::parse($ts)->toLocal()->diffForHumans() : null,
            ],
            'debugMode' => fn () => AppSettings::isDebugMode(),
            'availableUpdate' => fn () => Cache::get('available_update'),
            'support' => [
                'discordInviteUrl' => config('support.discord_invite_url'),
            ],
        ];
    }
}
