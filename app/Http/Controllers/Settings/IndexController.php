<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Settings\ValidatePath;
use App\Facades\Mtgo;
use App\Http\Controllers\Controller;
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
