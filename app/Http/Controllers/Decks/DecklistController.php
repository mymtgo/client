<?php

namespace App\Http\Controllers\Decks;

use App\Actions\Decks\BuildDecklist;
use App\Actions\Decks\GetDeckViewSharedProps;
use App\Http\Controllers\Controller;
use App\Models\Deck;
use App\Models\DeckVersion;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DecklistController extends Controller
{
    public function __invoke(Deck $deck, Request $request)
    {
        $shared = GetDeckViewSharedProps::run($deck);

        // Respect ?version= query param, fall back to latest version
        $deckVersion = $request->filled('version')
            ? DeckVersion::find($request->input('version')) ?? $deck->latestVersion
            : $deck->latestVersion;

        [$maindeck, $sideboard] = $deckVersion
            ? BuildDecklist::run($deckVersion)
            : [collect(), collect()];

        return Inertia::render('decks/Decklist', [
            ...$shared,
            'currentPage' => 'decklist',
            'maindeck' => $maindeck,
            'sideboard' => $sideboard,
        ]);
    }
}
