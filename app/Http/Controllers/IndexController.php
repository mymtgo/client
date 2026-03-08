<?php

namespace App\Http\Controllers;

use App\Data\Front\DeckData;
use App\Data\Front\MatchData;
use App\Models\Account;
use App\Models\Deck;
use App\Models\League;
use App\Models\MtgoMatch;
use Illuminate\Http\Request;
use Inertia\Inertia;

class IndexController extends Controller
{
    public function __invoke(Request $request)
    {
        $timeframe = $request->input('timeframe', 'week');
        [$start, $end] = $this->getTimeRange($timeframe);

        // Overall stats (single query)
        $stats = MtgoMatch::complete()
            ->when($this->activeAccountId(), fn ($q, $id) => $q
                ->whereHas('deckVersion', fn ($q2) => $q2->whereHas('deck', fn ($q3) => $q3->where('account_id', $id)))
            )
            ->whereBetween('started_at', [$start, $end])
            ->selectRaw('SUM(CASE WHEN games_won > games_lost THEN 1 ELSE 0 END) as wins')
            ->selectRaw('SUM(CASE WHEN games_won <= games_lost THEN 1 ELSE 0 END) as losses')
            ->selectRaw('SUM(games_won) as games_won')
            ->selectRaw('SUM(games_lost) as games_lost')
            ->first();

        $wins = (int) $stats->wins;
        $losses = (int) $stats->losses;
        $gamesWon = (int) $stats->games_won;
        $gamesLost = (int) $stats->games_lost;

        // Recent matches (paginated)
        $recentMatches = MtgoMatch::complete()
            ->when($this->activeAccountId(), fn ($q, $id) => $q
                ->whereHas('deckVersion', fn ($q2) => $q2->whereHas('deck', fn ($q3) => $q3->where('account_id', $id)))
            )
            ->with(['deck', 'games.players', 'opponentArchetypes.archetype', 'opponentArchetypes.player', 'league'])
            ->whereBetween('started_at', [$start, $end])
            ->orderByDesc('started_at')
            ->paginate(25);

        // Deck performance summary - only include decks with matches in timeframe
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
            'activeLeague' => $this->buildActiveLeague(),
            'formatChart' => $this->buildFormatChart(),
        ]);
    }

    private function buildActiveLeague(): ?array
    {
        $league = League::whereHas('matches', function ($q) {
            $q->complete();
            if ($id = $this->activeAccountId()) {
                $q->whereHas('deckVersion', fn ($q2) => $q2->whereHas('deck', fn ($q3) => $q3->where('account_id', $id)));
            }
        })->latest('started_at')->first();

        if (! $league) {
            return null;
        }

        $matches = MtgoMatch::complete()->where('league_id', $league->id)
            ->with(['deck'])
            ->latest('started_at')
            ->take(5)
            ->get()
            ->reverse()
            ->values();

        $wins = $matches->filter(fn ($m) => $m->games_won > $m->games_lost)->count();
        $losses = $matches->filter(fn ($m) => $m->games_won <= $m->games_lost)->count();

        return [
            'name' => $league->name,
            'format' => MtgoMatch::displayFormat($league->format),
            'phantom' => $league->phantom,
            'isActive' => $matches->count() < 5,
            'isTrophy' => $wins === 5,
            'deckName' => $matches->last()?->deck?->name,
            'results' => $matches
                ->map(fn ($m) => $m->games_won > $m->games_lost ? 'W' : 'L')
                ->pad(5, null)
                ->values()
                ->toArray(),
            'wins' => $wins,
            'losses' => $losses,
            'matchesRemaining' => 5 - $matches->count(),
        ];
    }

    private function buildFormatChart(): array
    {
        $rows = MtgoMatch::complete()
            ->when($this->activeAccountId(), fn ($q, $id) => $q
                ->whereHas('deckVersion', fn ($q2) => $q2->whereHas('deck', fn ($q3) => $q3->where('account_id', $id)))
            )
            ->selectRaw("strftime('%Y-%m', started_at) as month, format, SUM(CASE WHEN games_won > games_lost THEN 1 ELSE 0 END) as wins, COUNT(*) as total")
            ->where('started_at', '>=', now()->subMonths(6)->startOfMonth())
            ->groupBy('month', 'format')
            ->get()
            ->map(fn ($r) => [
                'month' => $r->month,
                'format' => MtgoMatch::displayFormat($r->format),
                'winrate' => round($r->wins / $r->total * 100),
            ]);

        $monthDates = collect(range(5, 0))->map(fn ($i) => now()->subMonths($i)->startOfMonth());
        $months = $monthDates->map(fn ($m) => $m->format('M'))->values()->toArray();
        $monthKeys = $monthDates->map(fn ($m) => $m->format('Y-m'))->values()->toArray();
        $formats = $rows->pluck('format')->unique()->values()->toArray();

        $data = collect($monthKeys)->map(function ($monthKey, $x) use ($rows, $formats) {
            $point = ['x' => $x];
            foreach ($formats as $format) {
                $row = $rows->first(fn ($r) => $r['month'] === $monthKey && $r['format'] === $format);
                $point[$format] = $row ? $row['winrate'] : null;
            }

            return $point;
        })->values()->toArray();

        return compact('months', 'formats', 'data');
    }

    private function activeAccountId(): ?int
    {
        return Account::active()->value('id');
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
