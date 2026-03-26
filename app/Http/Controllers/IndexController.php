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
use App\Actions\Util\Winrate;
use App\Data\Front\DeckData;
use App\Data\Front\MatchData;
use App\Models\Account;
use App\Models\Deck;
use App\Models\Game;
use App\Models\MtgoMatch;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

class IndexController extends Controller
{
    public function __invoke(Request $request)
    {
        $timeframe = $request->input('timeframe', 'week');
        $format = $request->input('format');
        [$start, $end] = $this->getTimeRange($timeframe);
        $accountId = Account::active()->value('id');

        // Available formats for the dropdown
        $formats = MtgoMatch::complete()
            ->when($accountId, fn ($q, $id) => $q->forAccount($id))
            ->distinct()
            ->pluck('format')
            ->sort()
            ->values()
            ->map(fn (string $f) => [
                'value' => $f,
                'label' => MtgoMatch::displayFormat($f),
            ])
            ->all();

        // Overall match stats using outcome column
        $matchStats = MtgoMatch::complete()
            ->when($accountId, fn ($q, $id) => $q->forAccount($id))
            ->when($format, fn ($q, $f) => $q->where('format', $f))
            ->whereBetween('started_at', [$start, $end])
            ->selectRaw("SUM(CASE WHEN outcome = 'win' THEN 1 ELSE 0 END) as wins")
            ->selectRaw("SUM(CASE WHEN outcome = 'loss' THEN 1 ELSE 0 END) as losses")
            ->first();

        $wins = (int) $matchStats->wins;
        $losses = (int) $matchStats->losses;

        // Game-level stats from games table
        $matchIds = MtgoMatch::complete()
            ->when($accountId, fn ($q, $id) => $q->forAccount($id))
            ->when($format, fn ($q, $f) => $q->where('format', $f))
            ->whereBetween('started_at', [$start, $end])
            ->pluck('id');

        $gamesWon = Game::whereIn('match_id', $matchIds)->where('won', true)->count();
        $gamesLost = Game::whereIn('match_id', $matchIds)->where('won', false)->count();

        // Deck performance summary
        $deckStats = Deck::forActiveAccount()->withCount([
            'wonMatches' => fn ($query) => $query->when($format, fn ($q, $f) => $q->where('format', $f))->whereBetween('started_at', [$start, $end]),
            'lostMatches' => fn ($query) => $query->when($format, fn ($q, $f) => $q->where('format', $f))->whereBetween('started_at', [$start, $end]),
            'matches' => fn ($query) => $query->when($format, fn ($q, $f) => $q->where('format', $f))->whereBetween('started_at', [$start, $end]),
        ])
            ->whereHas('matches', fn ($q) => $q->when($format, fn ($q, $f) => $q->where('format', $f))->whereBetween('started_at', [$start, $end]))
            ->get()
            ->map(fn ($deck) => DeckData::from($deck));

        $winrateDelta = GetWinrateDelta::run($accountId, $start, $end, $timeframe, $format);

        return Inertia::render('Index', [
            // Eager props
            'matchesWon' => $wins,
            'matchesLost' => $losses,
            'gamesWon' => $gamesWon,
            'gamesLost' => $gamesLost,
            'matchWinrate' => Winrate::percentage($wins, $losses),
            'gameWinrate' => Winrate::percentage($gamesWon, $gamesLost),
            'deckStats' => $deckStats,
            'timeframe' => $timeframe,
            'format' => $format,
            'formats' => $formats,
            'activeLeague' => GetActiveLeague::run(),
            'streak' => GetStreak::run($accountId, $start, $end, $format),
            'matchWinrateDelta' => $winrateDelta['matchDelta'],
            'gameWinrateDelta' => $winrateDelta['gameDelta'],
            'playDrawSplit' => GetPlayDrawSplit::run($accountId, $start, $end, $format),

            // Deferred props
            'lastSession' => Inertia::defer(fn () => GetLastSession::run($accountId, $format)),
            'matchupSpread' => Inertia::defer(fn () => GetDashboardMatchupSpread::run($accountId, $start, $end, $format)),
            'rollingForm' => Inertia::defer(fn () => GetRollingForm::run($accountId, $format)),
            'leagueDistribution' => Inertia::defer(fn () => GetDashboardLeagueDistribution::run($accountId, $format)),
            'recentMatches' => Inertia::defer(fn () => MatchData::collect(
                MtgoMatch::complete()
                    ->when($accountId, fn ($q, $id) => $q->forAccount($id))
                    ->when($format, fn ($q, $f) => $q->where('format', $f))
                    ->whereBetween('started_at', [$start, $end])
                    ->with(['games.players', 'opponentArchetypes.archetype', 'opponentArchetypes.player', 'league', 'deck'])
                    ->orderByDesc('started_at')
                    ->limit(10)
                    ->get()
            )),
        ]);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
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
