<?php

namespace App\Actions\Overlay;

use App\Actions\Accounts\FindLocalLoginId;
use App\Facades\AppSettings;
use App\Services\Sync\SyncApi;
use App\Services\Sync\SyncTokens;
use Illuminate\Support\Facades\Log;
use Throwable;

class ClearPublishedOverlay
{
    /** Blanks the hosted page now instead of waiting for it to go stale. Best effort. */
    public static function run(): void
    {
        AppSettings::setOverlayLastPublishedAt(null);
        AppSettings::setOverlayPublishError(null);

        $loginId = FindLocalLoginId::run();

        if ($loginId === null || AppSettings::isOffline() || ! app(SyncTokens::class)->linked()) {
            return;
        }

        try {
            app(SyncApi::class)->clearOverlay($loginId);
        } catch (Throwable $e) {
            Log::debug('Overlay clear failed', ['error' => $e->getMessage()]);
        }
    }
}
