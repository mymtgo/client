<?php

namespace App\Actions\Leagues;

use App\Facades\AppSettings;
use App\Models\Deck;
use Illuminate\Support\Facades\Storage;

class ResolveOverlayArt
{
    public const MODES = ['deck', 'none', 'custom'];

    /**
     * The chosen artwork mode. Never chosen means a user from before this
     * setting existed: an uploaded background keeps showing (custom),
     * everyone else gets the deck cover. Read-time, so no migration.
     */
    public static function mode(): string
    {
        return AppSettings::overlayArtwork() ?? (self::customUrl() !== null ? 'custom' : 'deck');
    }

    /** Public URL of the uploaded background, or null when unset or missing on disk. */
    public static function customUrl(): ?string
    {
        $path = AppSettings::overlayBackgroundPath();
        $disk = Storage::disk('overlay');

        return $path && $disk->exists($path) ? $disk->url($path) : null;
    }

    /**
     * @return array{url: string}|null
     */
    public static function run(?Deck $deck): ?array
    {
        $deckArt = $deck?->cover?->art_crop_url;

        $url = match (self::mode()) {
            'none' => null,
            'custom' => self::customUrl() ?? $deckArt,
            default => $deckArt,
        };

        return $url ? ['url' => $url] : null;
    }
}
