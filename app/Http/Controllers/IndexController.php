<?php

namespace App\Http\Controllers;

use App\Data\Front\DeckData;
use App\Data\Front\MatchData;
use App\Facades\Mtgo;
use App\Models\Deck;
use App\Models\MtgoMatch;
use Illuminate\Http\Request;
use Inertia\Inertia;

class IndexController extends Controller
{
    public function __invoke(Request $request)
    {
        $timeframe = $request->input('timeframe', 'week');
        [$start, $end] = $this->getTimeRange($timeframe);

        // Overall stats
        $matchesQuery = MtgoMatch::whereBetween('started_at', [$start, $end]);
        $wins = $matchesQuery->clone()->whereRaw('games_won > games_lost')->count();
        $losses = $matchesQuery->clone()->whereRaw('games_won <= games_lost')->count();
        $gamesWon = $matchesQuery->clone()->sum('games_won');
        $gamesLost = $matchesQuery->clone()->sum('games_lost');

        // Recent matches (paginated)
        $recentMatches = MtgoMatch::with(['deck', 'opponentArchetypes.archetype', 'league'])
            ->whereBetween('started_at', [$start, $end])
            ->orderByDesc('started_at')
            ->paginate(25);

        // Deck performance summary - only include decks with matches in timeframe
        $deckStats = Deck::withCount([
            'wonMatches' => fn ($query) => $query->whereBetween('started_at', [$start, $end]),
            'lostMatches' => fn ($query) => $query->whereBetween('started_at', [$start, $end]),
            'matches' => fn ($query) => $query->whereBetween('started_at', [$start, $end]),
        ])
            ->whereHas('matches', fn ($q) => $q->whereBetween('started_at', [$start, $end]))
            ->get()
            ->map(fn ($deck) => DeckData::from($deck));

        return Inertia::render('Index', [
            'matchesWon' => $wins,
            'matchesLost' => $losses,
            'gamesWon' => $gamesWon,
            'gamesLost' => $gamesLost,
            'matchWinrate' => round(100 * ($wins / (($wins + $losses) ?: 1))),
            'gameWinrate' => round(100 * ($gamesWon / (($gamesWon + $gamesLost) ?: 1))),
            'recentMatches' => MatchData::collect($recentMatches),
            'deckStats' => $deckStats,
            'timeframe' => $timeframe,
        ]);
    }

    /**
     * @return array{0: \Carbon\Carbon, 1: \Carbon\Carbon}
     */
    private function getTimeRange(string $timeframe): array
    {
        $end = now()->endOfDay();

        $start = match ($timeframe) {
            'biweekly' => now()->subWeeks(2)->startOfDay(),
            'monthly' => now()->subDays(30)->startOfDay(),
            'year' => now()->startOfYear()->startOfDay(),
            'alltime' => now()->startOfCentury()->startOfDay(),
            default => now()->subDays(7)->startOfDay(),
        };

        return [$start, $end];
    }
}
