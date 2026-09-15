<?php

namespace App\Http\Controllers\Settings\Pages;

use App\Facades\AppSettings;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class OverlaysController extends Controller
{
    public function __invoke(): Response
    {
        $overlayDisk = Storage::disk('overlay');
        $overlayBackgroundPath = AppSettings::overlayBackgroundPath();
        $overlayBackgroundUrl = $overlayBackgroundPath && $overlayDisk->exists($overlayBackgroundPath)
            ? $overlayDisk->url($overlayBackgroundPath)
            : null;

        return Inertia::render('settings/Overlays', [
            'currentPage' => 'overlays',
            'leagueWindowEnabled' => AppSettings::showLeagueWindow(),
            'gameOverlayEnabled' => AppSettings::showGameOverlay(),
            'draftNotesWindowEnabled' => AppSettings::showDraftNotesWindow(),
            'overlayShowOpponent' => AppSettings::overlayShowOpponent(),
            'overlayShowDrawOdds' => AppSettings::overlayShowDrawOdds(),
            'overlayShowSideboard' => AppSettings::overlayShowSideboard(),
            'overlayShowReveals' => AppSettings::overlayShowReveals(),
            'overlayBackgroundUrl' => $overlayBackgroundUrl,
        ]);
    }
}
