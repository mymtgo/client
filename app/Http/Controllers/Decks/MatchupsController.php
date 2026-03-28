<?php

namespace App\Http\Controllers\Decks;

use App\Actions\Decks\GetArchetypeMatchupSpread;
use App\Actions\Decks\GetDeckViewSharedProps;
use App\Concerns\HasTimeframeFilter;
use App\Http\Controllers\Controller;
use App\Models\Deck;
use App\Models\DeckVersion;
use Illuminate\Http\Request;
use Inertia\Inertia;

class MatchupsController extends Controller
{
    use HasTimeframeFilter;

    public function __invoke(Request $request, Deck $deck)
    {
        $timeframe = $request->input('timeframe', 'alltime');
        [$from, $to] = $this->getTimeRange($timeframe);

        $shared = GetDeckViewSharedProps::run($deck, $from, $to);

        $deckVersion = $request->filled('version')
            ? DeckVersion::find($request->input('version'))
            : null;

        return Inertia::render('decks/Matchups', [
            ...$shared,
            'currentVersionId' => $deckVersion?->id,
            'currentPage' => 'matchups',
            'timeframe' => $timeframe,
            'matchupSpread' => GetArchetypeMatchupSpread::run($deck, $from, $to, $deckVersion),
        ]);
    }
}
