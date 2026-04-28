<?php

namespace App\Http\Controllers\Setup;

use App\Actions\Decks\SyncDecks;
use App\Facades\AppSettings;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;

class SyncDecksController extends Controller
{
    public function sync(): RedirectResponse
    {
        try {
            SyncDecks::run();

            AppSettings::setSetupSkippedDecks(false);

            return redirect()->route('setup.index');
        } catch (\Throwable $e) {
            Log::error('Setup deck sync failed', ['exception' => $e]);
            report($e);

            return redirect()
                ->route('setup.index')
                ->with('setup_error_decks', 'Could not sync decks. Make sure your MTGO data path is correct and try again.');
        }
    }

    public function skip(): RedirectResponse
    {
        AppSettings::setSetupSkippedDecks(true);

        return redirect()->route('setup.index');
    }
}
