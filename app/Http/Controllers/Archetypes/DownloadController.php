<?php

namespace App\Http\Controllers\Archetypes;

use App\Jobs\DownloadArchetypes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;

class DownloadController
{
    public function __invoke(): RedirectResponse
    {
        try {
            DownloadArchetypes::dispatchSync();
        } catch (\Throwable $e) {
            Log::warning('DownloadArchetypes failed', ['error' => $e->getMessage()]);

            return back()->with('error', 'Could not connect to the archetype server. Please check your internet connection and try again.');
        }

        return back();
    }
}
