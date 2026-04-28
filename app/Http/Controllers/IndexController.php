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
use App\Concerns\HasTimeframeFilter;
use App\Data\Front\DeckData;
use App\Data\Front\MatchData;
use App\Models\Account;
use App\Models\Deck;
use App\Models\MtgoMatch;
use Illuminate\Http\Request;
use Inertia\Inertia;

class IndexController extends Controller
{
    use HasTimeframeFilter;

    public function __invoke(Request $request)
    {
        $timeframe = $request->input('timeframe', 'alltime');
        $format = $request->input('format');
        [$start, $end] = $this->getTimeRange($timeframe);
        $accountId = Account::currentId();

        // Consolidated match + game stats (single joined query, kept eager — fast and primary card data)
        $stats = MtgoMatch::complete()
            ->when($accountId, fn ($q, $id) => $q->forAccount($id))
            ->when($format, fn ($q, $f) => $q->where('format', $f))
            ->whereBetween('matches.started_at', [$start, $end])
            ->toBase()
            ->join('games', 'games.match_id', '=', 'matches.id')
            ->selectRaw("
                COUNT(DISTINCT matches.id) as total_matches,
                COUNT(DISTINCT CASE WHEN matches.outcome = 'win' THEN matches.id END) as wins,
                COUNT(DISTINCT CASE WHEN matches.outcome = 'loss' THEN matches.id END) as losses,
                SUM(CASE WHEN games.won = 1 THEN 1 ELSE 0 END) as games_won,
                SUM(CASE WHEN games.won = 0 THEN 1 ELSE 0 END) as games_lost
            ")
            ->first();

        $wins = (int) ($stats->wins ?? 0);
        $losses = (int) ($stats->losses ?? 0);
        $gamesWon = (int) ($stats->games_won ?? 0);
        $gamesLost = (int) ($stats->games_lost ?? 0);

        return Inertia::render('Index', [
            // Eager props — primary KPIs only
            'matchesWon' => $wins,
            'matchesLost' => $losses,
            'gamesWon' => $gamesWon,
            'gamesLost' => $gamesLost,
            'matchWinrate' => Winrate::percentage($wins, $losses),
            'gameWinrate' => Winrate::percentage($gamesWon, $gamesLost),
            'timeframe' => $timeframe,
            'format' => $format,
            'activeLeague' => GetActiveLeague::run(),

            // Deferred props — render dashboard immediately, hydrate widgets after
            'formats' => Inertia::defer(fn () => MtgoMatch::complete()
                ->when($accountId, fn ($q, $id) => $q->forAccount($id))
                ->distinct()
                ->pluck('format')
                ->sort()
                ->values()
                ->map(fn (string $f) => [
                    'value' => $f,
                    'label' => MtgoMatch::displayFormat($f),
                ])
                ->all()),
            'deckStats' => Inertia::defer(fn () => Deck::forActiveAccount()->with(['cover', 'archetype'])->withCount([
                'wonMatches' => fn ($query) => $query->when($format, fn ($q, $f) => $q->where('format', $f))->whereBetween('started_at', [$start, $end]),
                'lostMatches' => fn ($query) => $query->when($format, fn ($q, $f) => $q->where('format', $f))->whereBetween('started_at', [$start, $end]),
                'matches' => fn ($query) => $query->when($format, fn ($q, $f) => $q->where('format', $f))->whereBetween('started_at', [$start, $end]),
            ])
                ->whereHas('matches', fn ($q) => $q->when($format, fn ($q, $f) => $q->where('format', $f))->whereBetween('started_at', [$start, $end]))
                ->get()
                ->map(fn ($deck) => DeckData::from($deck))),
            'streak' => Inertia::defer(fn () => GetStreak::run($accountId, $start, $end, $format)),
            'matchWinrateDelta' => Inertia::defer(fn () => GetWinrateDelta::run($accountId, $start, $end, $timeframe, $format)['matchDelta']),
            'gameWinrateDelta' => Inertia::defer(fn () => GetWinrateDelta::run($accountId, $start, $end, $timeframe, $format)['gameDelta']),
            'playDrawSplit' => Inertia::defer(fn () => GetPlayDrawSplit::run($accountId, $start, $end, $format)),
            'lastSession' => Inertia::defer(fn () => GetLastSession::run($accountId, $format)),
            'matchupSpread' => Inertia::defer(fn () => GetDashboardMatchupSpread::run($accountId, $start, $end, $format)),
            'rollingForm' => Inertia::defer(fn () => GetRollingForm::run($accountId, $format)),
            'leagueDistribution' => Inertia::defer(fn () => GetDashboardLeagueDistribution::run($accountId, $format)),
            'recentMatches' => Inertia::defer(fn () => MatchData::collect(
                MtgoMatch::complete()
                    ->when($accountId, fn ($q, $id) => $q->forAccount($id))
                    ->when($format, fn ($q, $f) => $q->where('format', $f))
                    ->whereBetween('started_at', [$start, $end])
                    ->with(['games.players', 'opponentArchetypes.archetype', 'opponentArchetypes.player', 'league', 'deck.cover', 'deck.archetype'])
                    ->withCount([
                        'games as games_won_count' => fn ($q) => $q->where('won', true),
                        'games as games_lost_count' => fn ($q) => $q->where('won', false),
                    ])
                    ->orderByDesc('started_at')
                    ->limit(10)
                    ->get()
            )),
        ]);
    }
}
