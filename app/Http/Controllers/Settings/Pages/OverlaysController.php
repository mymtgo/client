<?php

namespace App\Http\Controllers\Settings\Pages;

use App\Actions\Decks\FindLastPlayedDeck;
use App\Actions\Leagues\BuildOverlayState;
use App\Actions\Leagues\OpenOverlayWindow;
use App\Actions\Leagues\ResolveOverlayArt;
use App\Facades\AppSettings;
use App\Facades\Mtgo;
use App\Http\Controllers\Controller;
use App\Services\Sync\SyncTokens;
use Inertia\Inertia;
use Inertia\Response;

class OverlaysController extends Controller
{
    public function __invoke(): Response
    {
        $deck = FindLastPlayedDeck::run();

        return Inertia::render('settings/Overlays', [
            'currentPage' => 'overlays',
            'leagueWindowEnabled' => AppSettings::showLeagueWindow(),
            'gameOverlayEnabled' => AppSettings::showGameOverlay(),
            'draftNotesWindowEnabled' => AppSettings::showDraftNotesWindow(),
            'overlayShowOpponent' => AppSettings::overlayShowOpponent(),
            'overlayShowDrawOdds' => AppSettings::overlayShowDrawOdds(),
            'overlayShowSideboard' => AppSettings::overlayShowSideboard(),
            'overlayShowReveals' => AppSettings::overlayShowReveals(),
            'overlayBackgroundUrl' => ResolveOverlayArt::customUrl(),
            'overlayArtwork' => ResolveOverlayArt::mode(),
            'overlaySize' => AppSettings::overlaySize(),
            'overlayDeckArtUrl' => $deck?->cover?->art_crop_url,
            'overlayDeck' => BuildOverlayState::deckPayload($deck),
            'overlayPublish' => AppSettings::overlayPublish(),
            'overlayPublicUrl' => self::publicUrl(),
            'overlayLastPublishedAt' => AppSettings::overlayLastPublishedAt(),
            'overlayPublishError' => AppSettings::overlayPublishError(),
            // Same box as the desktop window: the card plus room for its glow.
            'overlayObsSizes' => collect(OpenOverlayWindow::SIZES)->keys()->mapWithKeys(fn (string $size) => [$size => OpenOverlayWindow::windowSize($size)])->all(),
            'syncLinked' => app(SyncTokens::class)->linked(),
        ]);
    }

    /** The OBS Browser Source URL for whoever is signed in to MTGO. */
    private static function publicUrl(): ?string
    {
        $username = Mtgo::getUsername();

        return $username === null ? null : rtrim((string) config('mymtgo_api.url'), '/').'/overlays/'.rawurlencode($username);
    }
}
