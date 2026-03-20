<?php

namespace App\Http\Controllers;

use App\Actions\Dashboard\GetFormatChart;
use App\Actions\Leagues\GetActiveLeague;
use App\Data\Front\DeckData;
use App\Data\Front\MatchData;
use App\Models\Account;
use App\Models\Deck;
use App\Models\Game;
use App\Models\MtgoMatch;
use Illuminate\Http\Request;
use Inertia\Inertia;

class IndexController extends Controller
{
    public function __invoke(Request $request)
    {
        $timeframe = $request->input('timeframe', 'week');
        [$start, $end] = $this->getTimeRange($timeframe);
        $accountId = Account::active()->value('id');

        // Overall match-level stats
        $matchQuery = MtgoMatch::complete()
            ->when($accountId, fn ($q, $id) => $q
                ->whereHas('deckVersion', fn ($q2) => $q2->whereHas('deck', fn ($q3) => $q3->where('account_id', $id)))
            )
            ->whereBetween('started_at', [$start, $end]);

        $stats = $matchQuery->clone()
            ->selectRaw("SUM(CASE WHEN outcome = 'win' THEN 1 ELSE 0 END) as wins")
            ->selectRaw("SUM(CASE WHEN outcome = 'loss' THEN 1 ELSE 0 END) as losses")
            ->first();

        $wins = (int) $stats->wins;
        $losses = (int) $stats->losses;

        // Game-level stats from games table
        $matchIds = $matchQuery->clone()->pluck('matches.id');
        $gamesWon = Game::whereIn('match_id', $matchIds)->where('won', true)->count();
        $gamesLost = Game::whereIn('match_id', $matchIds)->where('won', false)->count();

        // Recent matches (paginated)
        $recentMatches = MtgoMatch::complete()
            ->when($accountId, fn ($q, $id) => $q
                ->whereHas('deckVersion', fn ($q2) => $q2->whereHas('deck', fn ($q3) => $q3->where('account_id', $id)))
            )
            ->with(['deck', 'games.players', 'opponentArchetypes.archetype', 'opponentArchetypes.player', 'league'])
            ->whereBetween('started_at', [$start, $end])
            ->orderByDesc('started_at')
            ->paginate(25);

        // Deck performance summary
        $deckStats = Deck::forActiveAccount()->withCount([
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
            'activeLeague' => GetActiveLeague::run(),
            'formatChart' => GetFormatChart::run(),
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
