<?php

namespace App\Actions\Sidecar;

use App\Facades\AppSettings;
use App\Sidecar\SidecarDownloadState;
use App\Sidecar\SidecarDownloadStore;
use App\Sidecar\SidecarPaths;

/**
 * One word for the settings card: what the helper is doing right now.
 * Null on platforms that can never run it, so the card is not shown.
 */
class ResolveHelperStatus
{
    /** @return array{state: string, progress: int|null, error: string|null}|null */
    public static function run(): ?array
    {
        if (! SidecarPaths::supported()) {
            return null;
        }

        if (! AppSettings::sidecarEnabled()) {
            return self::state('off');
        }

        if (AppSettings::sidecarTripped()) {
            return self::state('tripped');
        }

        if (SidecarPaths::exe() !== null) {
            $status = ReadSidecarStatus::run();

            return self::state($status !== null && ! $status->isStale() ? 'running' : 'starting');
        }

        // Before the download state: a job that bailed on offline mode leaves
        // a downloading state behind, which would otherwise read as a failure.
        if (AppSettings::isOffline()) {
            return self::state('offline');
        }

        $download = SidecarDownloadStore::read();

        if ($download->isFor((string) config('sidecar.version'))) {
            if ($download->isStale()) {
                return self::state('failed', error: SidecarDownloadState::ERROR_NETWORK);
            }

            if ($download->status === SidecarDownloadState::DOWNLOADING) {
                return self::state('downloading', progress: $download->progress());
            }

            if ($download->status === SidecarDownloadState::FAILED) {
                return self::state('failed', error: $download->error);
            }
        }

        return self::state('downloading');
    }

    /** @return array{state: string, progress: int|null, error: string|null} */
    private static function state(string $state, ?int $progress = null, ?string $error = null): array
    {
        return ['state' => $state, 'progress' => $progress, 'error' => $error];
    }
}
