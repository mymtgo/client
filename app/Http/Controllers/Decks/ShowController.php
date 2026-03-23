<?php

namespace App\Http\Controllers\Decks;

use App\Actions\Cards\GetCards;
use App\Actions\Decks\BuildDecklist;
use App\Actions\Decks\GetArchetypeMatchupSpread;
use App\Actions\Decks\GetDeckStats;
use App\Actions\Decks\GetDeckVersionStats;
use App\Actions\Leagues\FormatLeagueRuns;
use App\Actions\Leagues\GetLeagueResultDistribution;
use App\Data\Front\ArchetypeData;
use App\Data\Front\CardData;
use App\Data\Front\DeckData;
use App\Data\Front\MatchData;
use App\Http\Controllers\Controller;
use App\Models\Archetype;
use App\Models\Deck;
use App\Models\League;
use App\Models\MtgoMatch;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ShowController extends Controller
{
    public function __invoke(string $id, Request $request)
    {
        $deck = Deck::withCount(['wonMatches', 'lostMatches', 'matches'])
            ->withMax('matches', 'started_at')
            ->find($id);

        if (! $deck) {
            return redirect()->route('home');
        }

        $deckVersion = $deck->latestVersion;
        [$mainDeck, $sideboard] = BuildDecklist::run($deckVersion);

        $from = now()->subMonths(2)->startOfDay();
        $to = now()->endOfDay();

        $stats = GetDeckStats::run($deck, $from, $to);
        $allMatchIds = $stats['allMatchIds'];

        return Inertia::render('decks/Show', [
            // ── Eager: needed for the initial render ─────────────────────────
            'deck' => DeckData::from($deck),
            'trophies' => $stats['trophies'],
            'maindeck' => $mainDeck,
            'sideboard' => $sideboard,
            'matchesWon' => $stats['wins'],
            'matchesLost' => $stats['losses'],
            'gamesWon' => $stats['gamesWon'],
            'gamesLost' => $stats['gamesLost'],
            'matchWinrate' => $stats['matchWinrate'],
            'gameWinrate' => $stats['gameWinrate'],
            'gamesOtp' => $stats['otpWon'] + $stats['otpLost'],
            'gamesOtpWon' => $stats['otpWon'],
            'gamesOtpLost' => $stats['otpLost'],
            'otpRate' => $stats['otpRate'],
            'gamesOtd' => $stats['otdWon'] + $stats['otdLost'],
            'gamesOtdWon' => $stats['otdWon'],
            'gamesOtdLost' => $stats['otdLost'],
            'otdRate' => $stats['otdRate'],

            // ── Lazy closures: skipped on partial reloads that exclude them ──
            'chartData' => fn () => $this->buildDeckChartData($deck, $from, $to),
            'versions' => fn () => GetDeckVersionStats::run($deck, $from, $to),

            // ── Deferred: auto-loaded in background after initial render ─────
            // Default group — matches tab (loads immediately after paint)
            'matches' => Inertia::defer(function () use ($deck, $request) {
                $query = $deck->matches()->select('matches.*')->where('state', 'complete');

                if ($from = $request->input('filter_from')) {
                    $query->where('started_at', '>=', Carbon::parse($from)->startOfDay());
                }
                if ($to = $request->input('filter_to')) {
                    $query->where('started_at', '<=', Carbon::parse($to)->endOfDay());
                }
                if ($result = $request->input('filter_result')) {
                    if ($result === 'win') {
                        $query->won();
                    } elseif ($result === 'loss') {
                        $query->lost();
                    }
                }
                if ($type = $request->input('filter_type')) {
                    if ($type === 'league') {
                        $query->whereNotNull('league_id');
                    } elseif ($type === 'casual') {
                        $query->whereNull('league_id');
                    }
                }
                if ($archetype = $request->input('filter_archetype')) {
                    $query->whereHas('opponentArchetypes', fn ($q) => $q->where('archetype_id', $archetype));
                }

                return MatchData::collect(
                    $query->with(['games.players', 'opponentArchetypes.archetype', 'opponentArchetypes.player', 'league'])
                        ->orderByDesc('started_at')
                        ->paginate(50)
                );
            }),
            'archetypes' => Inertia::defer(function () use ($allMatchIds) {
                return Archetype::query()
                    ->whereHas('matchArchetypes', fn ($q) => $q->whereIn('mtgo_match_id', $allMatchIds))
                    ->withCount(['matchArchetypes' => fn ($q) => $q->whereIn('mtgo_match_id', $allMatchIds)])
                    ->orderByDesc('match_archetypes_count')
                    ->get()
                    ->map(fn (Archetype $a) => [
                        ...ArchetypeData::from($a)->toArray(),
                        'matchCount' => $a->match_archetypes_count,
                    ]);
            }),

            // Matchup spread — same 2-month window as chart
            'matchupSpread' => Inertia::defer(
                fn () => GetArchetypeMatchupSpread::run($deck, $from, $to),
            ),

            // Card stats — per-card performance aggregated from card_game_stats
            'cardStats' => Inertia::defer(function () use ($deckVersion, $request) {
                if (! $deckVersion) {
                    return ['stats' => [], 'archetypes' => []];
                }

                $opponentArchetypeId = $request->filled('card_stats_archetype')
                    ? (int) $request->input('card_stats_archetype')
                    : null;

                return [
                    'stats' => \App\Actions\Cards\GetCardGameStats::run($deckVersion, $opponentArchetypeId),
                    'archetypes' => \App\Actions\Cards\GetCardGameStats::availableArchetypes($deckVersion),
                ];
            }),
            'leagueResults' => Inertia::defer(
                fn () => GetLeagueResultDistribution::run($deck, $allMatchIds),
            ),
            'leagues' => Inertia::defer(function () use ($deck, $allMatchIds) {
                $leagues = League::whereHas('matches', fn ($q) => $q->whereIn('matches.id', $allMatchIds))
                    ->with(['deckVersion.deck'])
                    ->orderByDesc('started_at')
                    ->get();

                return FormatLeagueRuns::run($leagues, deckId: $deck->id);
            }, 'tabs'),

            // Versions group — sidebar deck version selector
            'versionDecklists' => Inertia::defer(
                fn () => $this->buildVersionDecklists($deck),
                'versions'
            ),
        ]);
    }

    private function buildVersionDecklists(Deck $deck): array
    {
        $versions = $deck->versions()->orderBy('modified_at')->get();

        if ($versions->isEmpty()) {
            return [];
        }

        $allCardRefs = $versions->flatMap(fn ($v) => $v->cards)->toArray();
        $cardModels = GetCards::run($allCardRefs)->keyBy('oracle_id');

        $result = [];
        foreach ($versions as $version) {
            $deckCards = collect($version->cards)->map(function ($cardRef) use ($cardModels) {
                $template = $cardModels->get($cardRef['oracle_id']);

                if (! $template) {
                    return null;
                }

                $card = clone $template;
                $card->sideboard = $cardRef['sideboard'] === 'true';
                $card->quantity = (int) $cardRef['quantity'];

                return CardData::from($card);
            })->filter()->sortBy('type');

            $mainDeck = $deckCards->filter(fn ($c) => ! $c->sideboard)
                ->groupBy('type')
                ->sortBy(fn ($cards, $type) => match ($type) {
                    'Creature' => 1, 'Instant' => 2, 'Sorcery' => 3, 'Land' => 10, default => 5
                });

            $sideboard = $deckCards->filter(fn ($c) => (bool) $c->sideboard)->values();

            $result[(string) $version->id] = [
                'maindeck' => $mainDeck,
                'sideboard' => $sideboard,
            ];
        }

        return $result;
    }

    private function buildDeckChartData(Deck $deck, Carbon $from, Carbon $to): array
    {
        $versionIds = $deck->versions()->pluck('id');

        $results = MtgoMatch::complete()
            ->selectRaw("strftime('%Y-%m-%d', started_at) as period, SUM(CASE WHEN outcome = 'win' THEN 1 ELSE 0 END) as wins, COUNT(*) as total")
            ->whereIn('deck_version_id', $versionIds)
            ->where('state', 'complete')
            ->whereBetween('started_at', [$from, $to])
            ->groupBy('period')
            ->orderBy('period')
            ->get()
            ->keyBy('period');

        if ($results->isEmpty()) {
            return [];
        }

        $carbonPeriod = CarbonPeriod::between($from->copy()->startOfDay(), $to->copy()->startOfDay())->days();

        return collect($carbonPeriod)->map(function (Carbon $point) use ($results) {
            $key = $point->format('Y-m-d');
            $row = $results->get($key);

            return [
                'date' => $key,
                'wins' => $row ? (int) $row->wins : 0,
                'losses' => $row ? (int) ($row->total - $row->wins) : 0,
                'winrate' => $row ? (string) round($row->wins / $row->total * 100) : null,
            ];
        })->toArray();
    }
}
