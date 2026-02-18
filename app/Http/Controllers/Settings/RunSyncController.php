<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Settings\ValidatePath;
use App\Facades\Mtgo;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Native\Desktop\Facades\Settings;

class RunSyncController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        $logOk = ValidatePath::forLogs(Settings::get('log_path', ''));
        $dataOk = ValidatePath::forData(Settings::get('log_data_path', ''));

        if (! $logOk['valid'] || ! $dataOk['valid']) {
            return back()->withErrors(['sync' => 'File paths are invalid. Fix them before syncing decks.']);
        }

        try {
            Mtgo::syncDecks();
            Cache::put('settings.last_sync_at', now()->toISOString(), now()->addDay());
        } catch (\Throwable $e) {
            return back()->withErrors(['sync' => 'Deck sync failed: '.$e->getMessage()]);
        }

        return back();
    }
}
