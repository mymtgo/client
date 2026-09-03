<?php

namespace App\Actions\Cards;

use Illuminate\Support\Facades\Storage;

class EncodeImageAsDataUrl
{
    /**
     * Inline a card image as a base64 data URL so html-to-image can rasterise
     * it without a cross-origin fetch.
     *
     * Prefers the locally cached copy; falls back to fetching the remote URL.
     * The remote fetch is a blocking network round-trip, so callers must only
     * invoke this on demand (a screenshot request), never while building a
     * page's props.
     */
    public static function run(?string $url, ?string $localStoragePath = null): ?string
    {
        if (! $url && ! $localStoragePath) {
            return null;
        }

        try {
            if ($localStoragePath && Storage::disk('cards')->exists($localStoragePath)) {
                $contents = Storage::disk('cards')->get($localStoragePath);
            } else {
                if (! $url) {
                    return null;
                }

                $contents = file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 5]]));
            }

            if ($contents === false || $contents === null) {
                return null;
            }

            $source = $localStoragePath ?? $url ?? '';
            $mime = 'image/jpeg';
            if (str_contains($source, '.png')) {
                $mime = 'image/png';
            } elseif (str_contains($source, '.webp')) {
                $mime = 'image/webp';
            }

            return 'data:'.$mime.';base64,'.base64_encode($contents);
        } catch (\Throwable) {
            return null;
        }
    }
}
