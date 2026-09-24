<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Sidecar\StartSidecarSupervisor;
use App\Facades\AppSettings;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Native\Desktop\Facades\ChildProcess;

class UpdateSidecarEnabledController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $request->validate([
            'enabled' => 'required|boolean',
        ]);

        $enabled = $request->boolean('enabled');

        AppSettings::setSidecarEnabled($enabled);

        if ($enabled) {
            StartSidecarSupervisor::run();
        } else {
            // Clear the crash history as well: the stop below raises a
            // ProcessExited that must not count against the tripwire, and a
            // deliberate off/on cycle should start the counter from clean.
            AppSettings::clearSidecarCrashes();
            ChildProcess::stop(StartSidecarSupervisor::ALIAS);
        }

        return back();
    }
}
