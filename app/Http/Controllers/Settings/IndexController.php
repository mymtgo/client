<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Settings\ValidatePath;
use App\Facades\Mtgo;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Card;
use App\Models\Deck;
use App\Models\LogCursor;
use App\Models\MtgoMatch;
use Inertia\Inertia;
use Inertia\Response;
use Locale;
use Native\Desktop\Facades\Settings;

class IndexController extends Controller
{
    public function __invoke(): Response
    {
        $logPath = Mtgo::getLogPath();
        $dataPath = Mtgo::getLogDataPath();

        return Inertia::render('settings/Index', [
            'logPath' => $logPath,
            'dataPath' => $dataPath,
            'watcherActive' => Settings::get('watcher_active') === null ? true : (bool) Settings::get('watcher_active'),
            'anonymousStats' => Settings::get('anonymous_stats') === null ? true : (bool) Settings::get('anonymous_stats'),
            'dateFormat' => Settings::get('date_format') ?? $this->detectAndStoreDateFormat(),
            'logPathStatus' => ValidatePath::forLogs($logPath),
            'dataPathStatus' => ValidatePath::forData($dataPath),
            'lastIngestAt' => LogCursor::first()?->updated_at,
            'lastSyncAt' => Deck::max('updated_at'),
            'missingCardCount' => Card::whereNull('name')->count(),
            'shareStats' => Settings::get('share_stats') === null ? false : (bool) Settings::get('share_stats'),
            'pendingMatches' => MtgoMatch::submittable()
                ->latest('started_at')
                ->get(['id', 'format', 'games_won', 'games_lost', 'started_at']),
            'hidePhantomLeagues' => (bool) Settings::get('hide_phantom_leagues'),
            'accounts' => Account::orderBy('username')->get(['id', 'username', 'tracked', 'active']),
            'appVersion' => config('nativephp.version'),
            'overlayEnabled' => (bool) Settings::get('overlay_enabled'),
        ]);
    }

    private function detectAndStoreDateFormat(): string
    {
        $locale = Locale::getDefault();
        $format = str_starts_with($locale, 'en_US') ? 'MDY' : 'DMY';

        Settings::set('date_format', $format);

        return $format;
    }
}
