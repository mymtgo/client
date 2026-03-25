<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Settings\ValidatePath;
use App\Facades\Mtgo;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\MtgoMatch;
use Inertia\Inertia;
use Inertia\Response;
use Native\Desktop\Facades\Settings;
use Native\Desktop\Facades\System;

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
            'logPathStatus' => ValidatePath::forLogs($logPath),
            'dataPathStatus' => ValidatePath::forData($dataPath),
            'shareStats' => Settings::get('share_stats') === null ? false : (bool) Settings::get('share_stats'),
            'pendingMatches' => MtgoMatch::submittable()
                ->latest('started_at')
                ->get(['id', 'format', 'outcome', 'started_at']),
            'hidePhantomLeagues' => (bool) Settings::get('hide_phantom_leagues'),
            'accounts' => Account::orderBy('username')->get(['id', 'username', 'tracked', 'active']),
            'debugMode' => (bool) Settings::get('debug_mode'),
            'appVersion' => config('nativephp.version'),
            'timezone' => Settings::get('timezone') ?: System::timezone() ?: 'UTC',
            'detectedTimezone' => System::timezone() ?: 'UTC',
            'leagueWindowEnabled' => (bool) Settings::get('league_window'),
            'opponentWindowEnabled' => (bool) Settings::get('opponent_window'),
            'deckWindowEnabled' => (bool) Settings::get('deck_window'),
        ]);
    }
}
