<?php

namespace App\Http\Controllers\Settings;

use App\Events\LeagueOverlayChanged;
use App\Facades\AppSettings;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class UpdateOverlayDeckLabelController extends Controller
{
    /** Saves the streamer's name for a deck; the window, preview and OBS all read it. */
    public function __invoke(Request $request): Response
    {
        $validated = $request->validate([
            'deck_id' => ['required', 'integer', 'exists:decks,id'],
            'label' => ['nullable', 'string', 'max:100'],
        ]);

        AppSettings::setOverlayDeckLabel((int) $validated['deck_id'], $validated['label'] ?? null);

        LeagueOverlayChanged::dispatch();

        return response()->noContent();
    }
}
