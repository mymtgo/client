<?php

namespace App\Actions\Overlay;

use App\Facades\AppSettings;
use App\Services\Sync\SyncApi;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class SyncOverlayBackground
{
    /**
     * The public URL of the custom background, uploading it first when the
     * local file changed since the last upload. Null when there is no local
     * file or the upload failed, so the caller falls back to deck art.
     */
    public static function run(): ?string
    {
        $path = AppSettings::overlayBackgroundPath();
        $disk = Storage::disk('overlay');

        if (! $path || ! $disk->exists($path)) {
            return null;
        }

        $contents = (string) $disk->get($path);
        $hash = sha1($contents);
        $remote = AppSettings::overlayBackgroundRemote();

        if ($remote !== null && $remote['hash'] === $hash) {
            return $remote['url'];
        }

        try {
            $url = app(SyncApi::class)->uploadOverlayBackground($contents, basename($path));
        } catch (Throwable $e) {
            Log::debug('Overlay background upload failed', ['error' => $e->getMessage()]);

            return null;
        }

        AppSettings::setOverlayBackgroundRemote(['url' => $url, 'hash' => $hash]);

        return $url;
    }

    /** Removes the uploaded copy. Failure is ignored: the next upload replaces it. */
    public static function forget(): void
    {
        if (AppSettings::overlayBackgroundRemote() === null) {
            return;
        }

        try {
            app(SyncApi::class)->deleteOverlayBackground();
        } catch (Throwable $e) {
            Log::debug('Overlay background delete failed', ['error' => $e->getMessage()]);
        }

        AppSettings::setOverlayBackgroundRemote(null);
    }
}
