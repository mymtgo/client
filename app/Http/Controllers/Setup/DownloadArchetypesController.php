<?php

namespace App\Http\Controllers\Setup;

use App\Facades\AppSettings;
use App\Http\Controllers\Controller;
use App\Jobs\DownloadArchetypes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;

class DownloadArchetypesController extends Controller
{
    public function download(): RedirectResponse
    {
        try {
            DownloadArchetypes::dispatchSync();

            AppSettings::setSetupSkippedArchetypes(false);

            return redirect()->route('setup.index');
        } catch (\Throwable $e) {
            Log::error('Setup archetype download failed', ['exception' => $e]);
            report($e);

            return redirect()
                ->route('setup.index')
                ->with('setup_error_archetypes', 'Could not download archetypes. Check your internet connection and try again.');
        }
    }

    public function skip(): RedirectResponse
    {
        AppSettings::setSetupSkippedArchetypes(true);

        return redirect()->route('setup.index');
    }
}
