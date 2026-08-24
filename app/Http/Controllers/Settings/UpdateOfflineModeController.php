<?php

namespace App\Http\Controllers\Settings;

use App\Facades\AppSettings;
use App\Http\Controllers\Controller;
use App\Jobs\DownloadArchetypes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class UpdateOfflineModeController extends Controller
{
    /**
     * Toggle offline mode.
     *
     * Rejoining dispatches an archetype resync. Nothing else refreshes the
     * catalog for an established install, so without this a long offline
     * period leaves a permanently stale catalog.
     *
     * Leaving offline mode also starts a cooldown before it can be switched
     * back on, so that pulling a fresh catalogue and immediately going private
     * again costs a day online rather than two clicks. The gate is repeated
     * here rather than left to the UI because this route stays reachable
     * whatever the settings page renders.
     */
    public function __invoke(Request $request): RedirectResponse
    {
        $request->validate([
            'enabled' => 'required|boolean',
        ]);

        $enabled = $request->boolean('enabled');
        $wasOffline = AppSettings::isOffline();

        if ($enabled && ! $wasOffline && AppSettings::isOfflineModeLocked()) {
            return back()->with(
                'error',
                'Offline mode is on cooldown after coming back online. You can turn it back on '
                .Carbon::parse(AppSettings::offlineModeLockedUntil())->toLocal()->diffForHumans().'.'
            );
        }

        AppSettings::setOffline($enabled);

        if ($wasOffline && ! $enabled) {
            AppSettings::setOfflineModeLockedUntil(
                Carbon::now()->toLocal()->addDay()->startOfDay()->toIso8601String()
            );

            DownloadArchetypes::dispatch();
        }

        return back();
    }
}
