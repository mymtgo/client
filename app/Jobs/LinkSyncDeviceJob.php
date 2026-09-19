<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Sync\Auth\BuildAuthorizationRequest;
use App\Facades\AppSettings;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Native\Desktop\Facades\Shell;
use Throwable;

/**
 * Opens the system browser to the server's OAuth consent screen. The
 * redirect comes back as a mymtgo:// deep link handled by
 * HandleSyncAuthCallback, so this job's work ends the moment the browser is
 * open; the settings page reads SyncTokens::linked() afterwards to learn the
 * outcome. Any failure is caught and logged rather than left to bubble.
 */
class LinkSyncDeviceJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 60;

    public int $uniqueFor = 60;

    public function __construct()
    {
        $this->onQueue('sync');
    }

    public function uniqueId(): string
    {
        return 'link-sync-device';
    }

    public function handle(BuildAuthorizationRequest $buildRequest): void
    {
        if (AppSettings::isOffline()) {
            Log::info('Sync device link skipped: offline mode is on.');

            return;
        }

        try {
            Shell::openExternal($buildRequest->run());
        } catch (Throwable $e) {
            Log::error('Sync device link failed.', ['error' => $e->getMessage()]);
        }
    }
}
