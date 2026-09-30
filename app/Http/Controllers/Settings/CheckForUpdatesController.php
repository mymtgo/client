<?php

namespace App\Http\Controllers\Settings;

use App\Actions\AutoUpdate\ResolveUpdateStatus;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Native\Desktop\Facades\AutoUpdater;

class CheckForUpdatesController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        if (! ResolveUpdateStatus::active()) {
            return back();
        }

        ResolveUpdateStatus::recordCheck('checking');

        try {
            AutoUpdater::checkForUpdates();
        } catch (\Throwable $e) {
            ResolveUpdateStatus::recordCheck('error', error: $e->getMessage());
        }

        return back();
    }
}
