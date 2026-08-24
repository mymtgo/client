<?php

namespace App\Http\Middleware;

use App\Facades\AppSettings;
use App\Models\Account;
use App\Models\Game;
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
                'success' => $request->session()->get('success'),
            ],
            'status' => fn () => [
                'watcherRunning' => AppSettings::isWatcherActive(),
                'lastIngestAt' => $ts = LogCursor::max('updated_at'),
                'lastIngestAtHuman' => $ts ? Carbon::parse($ts)->toLocal()->diffForHumans() : null,
                'pendingMatchCount' => MtgoMatch::submittable()->count(),
            ],
            'debugMode' => fn () => AppSettings::isDebugMode(),
            'offlineMode' => fn () => AppSettings::isOffline(),
            'activeAccount' => fn () => Account::current()?->username,
            'accounts' => fn () => Account::tracked()->orderBy('username')->get(['id', 'username', 'active']),
            'availableUpdate' => fn () => Cache::get('available_update'),
            'support' => [
                'discordInviteUrl' => config('support.discord_invite_url'),
            ],
            'donation' => fn () => [
                'showModal' => $this->shouldShowDonationModal(),
                'tixHandle' => config('support.tix_handle'),
            ],
        ];
    }

    /**
     * The one-time takeover fires only once a configurable number of games have
     * been tracked and the prompt has not been dismissed. A missing tix handle
     * suppresses it — there is no destination to ask players to trade to. The
     * seen check is evaluated first so the game count query is skipped entirely
     * for users who have already dismissed the prompt.
     */
    private function shouldShowDonationModal(): bool
    {
        if (AppSettings::donationPromptSeen()) {
            return false;
        }

        if (blank(config('support.tix_handle'))) {
            return false;
        }

        return Game::count() >= (int) config('support.prompt_after_games');
    }
}
