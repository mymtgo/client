<?php

namespace App\Http\Controllers;

use App\Actions\Dashboard\GetDashboardLeagueDistribution;
use App\Actions\Dashboard\GetDashboardMatchupSpread;
use App\Actions\Dashboard\GetLastSession;
use App\Actions\Dashboard\GetPlayDrawSplit;
use App\Actions\Dashboard\GetRollingForm;
use App\Actions\Dashboard\GetStreak;
use App\Actions\Dashboard\GetWinrateDelta;
use App\Actions\Leagues\GetActiveLeague;
use App\Data\Front\DeckData;
use App\Models\Account;
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
        $accountId = Account::active()->value('id');

        // Overall match stats using outcome column
        $matchStats = MtgoMatch::complete()
            ->when($accountId, fn ($q, $id) => $q
                ->whereHas('deckVersion', fn ($q2) => $q2->whereHas('deck', fn ($q3) => $q3->where('account_id', $id)))
            )
            ->whereBetween('started_at', [$start, $end])
            ->selectRaw("SUM(CASE WHEN outcome = 'win' THEN 1 ELSE 0 END) as wins")
            ->selectRaw("SUM(CASE WHEN outcome = 'loss' THEN 1 ELSE 0 END) as losses")
            ->first();

        $wins = (int) $matchStats->wins;
        $losses = (int) $matchStats->losses;

        // Game-level stats from games table
        $matchIds = MtgoMatch::complete()
            ->when($accountId, fn ($q, $id) => $q
                ->whereHas('deckVersion', fn ($q2) => $q2->whereHas('deck', fn ($q3) => $q3->where('account_id', $id)))
            )
            ->whereBetween('started_at', [$start, $end])
            ->pluck('id');

        $gamesWon = \App\Models\Game::whereIn('match_id', $matchIds)->where('won', true)->count();
        $gamesLost = \App\Models\Game::whereIn('match_id', $matchIds)->where('won', false)->count();

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
            // Eager props
            'matchesWon' => $wins,
            'matchesLost' => $losses,
            'gamesWon' => $gamesWon,
            'gamesLost' => $gamesLost,
            'matchWinrate' => round(100 * ($wins / (($wins + $losses) ?: 1))),
            'gameWinrate' => round(100 * ($gamesWon / (($gamesWon + $gamesLost) ?: 1))),
            'deckStats' => $deckStats,
            'timeframe' => $timeframe,
            'activeLeague' => GetActiveLeague::run(),
            'streak' => GetStreak::run($accountId, $start, $end),
            'matchWinrateDelta' => GetWinrateDelta::run($accountId, $start, $end, $timeframe)['matchDelta'],
            'gameWinrateDelta' => GetWinrateDelta::run($accountId, $start, $end, $timeframe)['gameDelta'],
            'playDrawSplit' => GetPlayDrawSplit::run($accountId, $start, $end),

            // Deferred props
            'lastSession' => Inertia::defer(fn () => GetLastSession::run($accountId)),
            'matchupSpread' => Inertia::defer(fn () => GetDashboardMatchupSpread::run($accountId, $start, $end)),
            'rollingForm' => Inertia::defer(fn () => GetRollingForm::run($accountId)),
            'leagueDistribution' => Inertia::defer(fn () => GetDashboardLeagueDistribution::run($accountId)),
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
