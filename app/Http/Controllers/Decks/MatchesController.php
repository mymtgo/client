<?php

namespace App\Http\Controllers\Decks;

use App\Actions\Decks\GetDeckViewSharedProps;
use App\Actions\Leagues\GetManualMatchLeagueOptions;
use App\Actions\Matches\BuildMatchListProps;
use App\Concerns\HasTimeframeFilter;
use App\Http\Controllers\Controller;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\MtgoMatch;
use Illuminate\Http\Request;
use Inertia\Inertia;

class MatchesController extends Controller
{
    use HasTimeframeFilter;

    public function __invoke(Deck $deck, Request $request)
    {
        $timeframe = $request->input('timeframe', 'alltime');
        [$from, $to] = $this->getTimeRange($timeframe);

        $shared = GetDeckViewSharedProps::run($deck, $from, $to);

        $deckVersion = $request->filled('version')
            ? DeckVersion::find($request->input('version'))
            : null;

        $scoped = $deck->matches()->select('matches.*')->where('state', 'complete')
            ->when($deckVersion, fn ($q) => $q->where('deck_version_id', $deckVersion->id))
            ->whereBetween('started_at', [$from, $to]);

        $archetypeFormat = MtgoMatch::archetypeFormat($deck->format);

        $pendingArchetypeCount = $deck->matches()
            ->whereNotNull('archetype_detection_queued_at')
            ->count();

        return Inertia::render('decks/Matches', [
            ...$shared,
            'currentVersionId' => $deckVersion?->id,
            'currentPage' => 'matches',
            'timeframe' => $timeframe,
            ...BuildMatchListProps::run($scoped, $request, $archetypeFormat),
            'pendingArchetypeCount' => $pendingArchetypeCount,
            'manualMatchDeck' => [
                'id' => $deck->id,
                'name' => $deck->name,
                'format' => MtgoMatch::displayFormat($deck->format),
                'formatCode' => $deck->format,
            ],
            'manualMatchLeagues' => GetManualMatchLeagueOptions::run($deck->id),

        ]);
    }
}
