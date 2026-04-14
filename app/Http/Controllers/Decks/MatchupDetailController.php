<?php

namespace App\Http\Controllers\Decks;

use App\Actions\Decks\GetArchetypeMatchupDetail;
use App\Concerns\HasTimeframeFilter;
use App\Http\Controllers\Controller;
use App\Models\Archetype;
use App\Models\Deck;
use App\Models\DeckVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MatchupDetailController extends Controller
{
    use HasTimeframeFilter;

    public function __invoke(Request $request, Deck $deck, Archetype $archetype): JsonResponse
    {
        $timeframe = $request->input('timeframe', 'alltime');
        [$from, $to] = $this->getTimeRange($timeframe);

        $deckVersion = $request->filled('version')
            ? DeckVersion::find($request->input('version'))
            : null;

        return response()->json(
            GetArchetypeMatchupDetail::run($deck, $archetype, $from, $to, $deckVersion)
        );
    }
}
