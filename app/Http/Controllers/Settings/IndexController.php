<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Settings\ValidatePath;
use App\Facades\Mtgo;
use App\Http\Controllers\Controller;
use App\Models\Card;
use App\Models\MtgoMatch;
use Illuminate\Support\Facades\Cache;
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
            'lastIngestAt' => Cache::get('settings.last_ingest_at'),
            'lastSyncAt' => Cache::get('settings.last_sync_at'),
            'missingCardCount' => Card::whereNull('scryfall_id')->count(),
            'shareStats' => Settings::get('share_stats') === null ? false : (bool) Settings::get('share_stats'),
            'pendingMatches' => MtgoMatch::whereNull('submitted_at')
                ->whereNotNull('deck_version_id')
                ->whereHas('archetypes')
                ->latest('started_at')
                ->get(['id', 'format', 'games_won', 'games_lost', 'started_at']),
            'appVersion' => config('nativephp.version'),
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
