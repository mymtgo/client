<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Settings\ValidatePath;
use App\Facades\AppSettings;
use App\Facades\Mtgo;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;

class RunSyncController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        $logOk = ValidatePath::forLogs(AppSettings::logPath());
        $dataOk = ValidatePath::forData(AppSettings::logDataPath());

        if (! $logOk['valid'] || ! $dataOk['valid']) {
            return back()->withErrors(['sync' => 'File paths are invalid. Fix them before syncing decks.']);
        }

        try {
            Mtgo::syncDecks();
        } catch (\Throwable $e) {
            return back()->withErrors(['sync' => 'Deck sync failed: '.$e->getMessage()]);
        }

        return back();
    }
}
