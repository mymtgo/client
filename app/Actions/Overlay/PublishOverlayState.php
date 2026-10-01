<?php

namespace App\Actions\Overlay;

use App\Actions\Accounts\FindLocalLoginId;
use App\Actions\Leagues\BuildOverlayState;
use App\Actions\Leagues\ResolveOverlayArt;
use App\Facades\AppSettings;
use App\Models\Deck;
use App\Services\Sync\SyncApi;
use App\Services\Sync\SyncTokens;
use Illuminate\Support\Facades\Log;
use Throwable;

class PublishOverlayState
{
    /**
     * Pushes the league card to the hosted OBS page. Independent of the
     * league window. A failed push is dropped: the next change or the 30s
     * heartbeat sends the then-current state, so nothing is retried.
     */
    public static function run(): void
    {
        if (! AppSettings::overlayPublish() || AppSettings::isOffline() || ! app(SyncTokens::class)->linked()) {
            return;
        }

        $loginId = FindLocalLoginId::run();

        if ($loginId === null) {
            AppSettings::setOverlayPublishError('no_login_id');

            return;
        }

        try {
            app(SyncApi::class)->publishOverlay($loginId, self::payload());
        } catch (Throwable $e) {
            Log::debug('Overlay publish failed', ['error' => $e->getMessage()]);
            AppSettings::setOverlayPublishError(match ($e->getCode()) {
                409 => 'not_claimed',
                422 => 'rejected',
                default => 'unreachable',
            });

            return;
        }

        AppSettings::setOverlayLastPublishedAt(now()->toIso8601String());
        AppSettings::setOverlayPublishError(null);
    }

    /**
     * BuildOverlayState with art a remote page can load: local disk URLs
     * (127.0.0.1) are replaced by the Scryfall art or the uploaded background.
     *
     * @return array<string, mixed>
     */
    public static function payload(): array
    {
        $state = BuildOverlayState::run();
        $state['art'] = self::publicArt($state['deck']['id'] ?? null);

        return $state;
    }

    /** @return array{url: string}|null */
    private static function publicArt(?int $deckId): ?array
    {
        $deckArt = $deckId === null ? null : Deck::query()->with('cover')->find($deckId)?->cover?->art_crop;

        $url = match (ResolveOverlayArt::mode()) {
            'none' => null,
            'custom' => SyncOverlayBackground::run() ?? $deckArt,
            default => $deckArt,
        };

        return $url ? ['url' => $url] : null;
    }
}
