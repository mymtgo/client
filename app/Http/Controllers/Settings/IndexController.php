<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Settings\ValidatePath;
use App\Facades\AppSettings;
use App\Facades\Mtgo;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\MtgoMatch;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class IndexController extends Controller
{
    private function getLocalImagesSize(): string
    {
        $files = Storage::disk('cards')->allFiles();
        $disk = Storage::disk('cards');
        $bytes = array_sum(array_map(fn (string $file) => $disk->exists($file) ? $disk->size($file) : 0, $files));

        return match (true) {
            $bytes >= 1073741824 => number_format($bytes / 1073741824, 1).' GB',
            $bytes >= 1048576 => number_format($bytes / 1048576, 1).' MB',
            $bytes >= 1024 => number_format($bytes / 1024, 0).' KB',
            default => $bytes.' B',
        };
    }

    public function __invoke(): Response
    {
        $logPath = Mtgo::getLogPath();
        $dataPath = Mtgo::getLogDataPath();

        $overlayDisk = Storage::disk('overlay');
        $overlayBackgroundPath = AppSettings::overlayBackgroundPath();
        $overlayBackgroundUrl = $overlayBackgroundPath && $overlayDisk->exists($overlayBackgroundPath)
            ? $overlayDisk->url($overlayBackgroundPath)
            : null;

        return Inertia::render('settings/Index', [
            'logPath' => $logPath,
            'dataPath' => $dataPath,
            'watcherActive' => AppSettings::isWatcherActive(),
            'logPathStatus' => ValidatePath::forLogs($logPath),
            'dataPathStatus' => ValidatePath::forData($dataPath),
            'shareStats' => AppSettings::shouldTransmitMatches(),
            'pendingMatches' => MtgoMatch::submittable()
                ->latest('started_at')
                ->get(['id', 'format', 'outcome', 'started_at']),
            'accounts' => Account::orderBy('username')->get(['id', 'username', 'tracked', 'active']),
            'debugMode' => AppSettings::isDebugMode(),
            'appVersion' => config('nativephp.version'),
            'leagueWindowEnabled' => AppSettings::showLeagueWindow(),
            'opponentWindowEnabled' => AppSettings::showOpponentWindow(),
            'deckWindowEnabled' => AppSettings::showDeckWindow(),
            'overlayBackgroundUrl' => $overlayBackgroundUrl,
            'localImages' => AppSettings::downloadImagesLocally(),
            'localImagesSize' => $this->getLocalImagesSize(),
            'autostartEnabled' => AppSettings::autostartEnabled(),
            'trayAvailable' => PHP_OS_FAMILY !== 'Linux',
        ]);
    }
}
