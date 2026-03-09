<?php

namespace App\Http\Controllers\Decks;

use App\Actions\Cards\GetCards;
use App\Actions\Decks\GetArchetypeMatchupSpread;
use App\Data\Front\ArchetypeData;
use App\Data\Front\CardData;
use App\Data\Front\DeckData;
use App\Data\Front\LeagueData;
use App\Data\Front\MatchData;
use App\Http\Controllers\Controller;
use App\Models\Archetype;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\Game;
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
        [$mainDeck, $sideboard] = $this->buildDecklist($deckVersion);

        $from = now()->subMonths(2)->startOfDay();
        $to = now()->endOfDay();

        $matchesQuery = $deck->matches()->select('matches.*')->whereNull('deleted_at')
            ->whereBetween('started_at', [$from, $to]);

        $losses = $matchesQuery->clone()->whereRaw('games_won < games_lost')->count();
        $wins = $matchesQuery->clone()->whereRaw('games_won > games_lost')->count();
        $gamesWon = $matchesQuery->clone()->sum('games_won');
        $gamesLost = $matchesQuery->clone()->sum('games_lost');

        $matchIds = $matchesQuery->clone()->select('matches.id')->pluck('matches.id');
        $matchGamesQuery = Game::whereHas('match', fn ($q) => $q->whereIn('match_id', $matchIds));

        $gamesotp = $matchGamesQuery->clone()->whereHas('localPlayers', fn ($q) => $q->where('on_play', 1));
        $gamesotd = $matchGamesQuery->clone()->whereHas('localPlayers', fn ($q) => $q->where('on_play', 0));

        $gamesotpWon = $gamesotp->clone()->where('won', 1)->count();
        $gamesotpLost = $gamesotp->clone()->where('won', 0)->count();
        $gamesotdWon = $gamesotd->clone()->where('won', 1)->count();
        $gamesotdLost = $gamesotd->clone()->where('won', 0)->count();

        $totalMatches = $matchesQuery->count();
        $matchWinrate = round(100 * ($wins / ($totalMatches ?: 1)));
        $gameWinrate = round(100 * ($gamesWon / (($gamesWon + $gamesLost) ?: 1)));
        $otpRate = round(100 * ($gamesotpWon / (($gamesotpWon + $gamesotpLost) ?: 1)));
        $otdRate = round(100 * ($gamesotdWon / (($gamesotdWon + $gamesotdLost) ?: 1)));
        $gamesotpCount = $gamesotpWon + $gamesotpLost;
        $gamesotdCount = $gamesotdWon + $gamesotdLost;

        // All match IDs for this deck (used by leagues tab)
        $allMatchIds = $deck->matches()->select('matches.id')->whereNull('deleted_at')->pluck('matches.id');

        // Trophies = leagues where all matches were won (5-0)
        $trophies = League::whereHas('matches', fn ($q) => $q->whereIn('matches.id', $allMatchIds))
            ->withCount([
                'matches as won_count' => fn ($q) => $q->whereIn('matches.id', $allMatchIds)->whereRaw('games_won > games_lost'),
                'matches as total_count' => fn ($q) => $q->whereIn('matches.id', $allMatchIds),
            ])
            ->get()
            ->filter(fn ($l) => $l->total_count === 5 && $l->won_count === 5)
            ->count();

        return Inertia::render('decks/Show', [
            // ── Eager: needed for the initial render ─────────────────────────
            'deck' => DeckData::from($deck),
            'trophies' => $trophies,
            'maindeck' => $mainDeck,
            'sideboard' => $sideboard,
            'matchesWon' => $wins,
            'matchesLost' => $losses,
            'gamesWon' => $gamesWon,
            'gamesLost' => $gamesLost,
            'matchWinrate' => $matchWinrate,
            'gameWinrate' => $gameWinrate,
            'gamesOtp' => $gamesotpCount,
            'gamesOtpWon' => $gamesotpWon,
            'gamesOtpLost' => $gamesotpLost,
            'otpRate' => $otpRate,
            'gamesOtd' => $gamesotdCount,
            'gamesOtdWon' => $gamesotdWon,
            'gamesOtdLost' => $gamesotdLost,
            'otdRate' => $otdRate,

            // ── Lazy closures: skipped on partial reloads that exclude them ──
            'chartData' => fn () => $this->buildDeckChartData($deck, $from, $to),
            'versions' => fn () => $this->buildVersionsList($deck, $from, $to, $wins, $losses, $gamesWon, $gamesLost, $matchWinrate, $gameWinrate, $gamesotpWon, $gamesotpLost, $otpRate, $gamesotdWon, $gamesotdLost, $otdRate),

            // ── Deferred: auto-loaded in background after initial render ─────
            // Default group — matches tab (loads immediately after paint)
            'matches' => Inertia::defer(function () use ($deck, $request) {
                $query = $deck->matches()->select('matches.*')->whereNull('deleted_at');

                if ($from = $request->input('filter_from')) {
                    $query->where('started_at', '>=', Carbon::parse($from)->startOfDay());
                }
                if ($to = $request->input('filter_to')) {
                    $query->where('started_at', '<=', Carbon::parse($to)->endOfDay());
                }
                if ($result = $request->input('filter_result')) {
                    if ($result === 'win') {
                        $query->whereRaw('games_won > games_lost');
                    } elseif ($result === 'loss') {
                        $query->whereRaw('games_won < games_lost');
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
            'leagues' => Inertia::defer(function () use ($allMatchIds) {
                $leagues = League::with(['matches' => fn ($q) => $q
                    ->whereIn('matches.id', $allMatchIds)
                    ->with(['games.players', 'opponentArchetypes.archetype', 'opponentArchetypes.player', 'league'])])
                    ->whereHas('matches', fn ($q) => $q->whereIn('matches.id', $allMatchIds))
                    ->latest('started_at')
                    ->get();

                return LeagueData::collect($leagues);
            }, 'tabs'),

            // Versions group — sidebar deck version selector
            'versionDecklists' => Inertia::defer(
                fn () => $this->buildVersionDecklists($deck),
                'versions'
            ),
        ]);
    }

    private function buildDecklist(DeckVersion $deckVersion): array
    {
        $cards = GetCards::run($deckVersion->cards);

        $deckCards = collect($deckVersion->cards)->map(function ($card) use ($cards) {
            $cardModel = $cards->first(fn ($c) => $c->oracle_id == $card['oracle_id']);

            if (! $cardModel) {
                return null;
            }

            $cardModel = clone $cardModel;
            $cardModel->sideboard = $card['sideboard'] === 'true';
            $cardModel->quantity = $card['quantity'];

            return CardData::from($cardModel);
        })->filter()->sortBy('type')->values();

        $mainDeck = $deckCards->filter(fn ($c) => ! $c->sideboard)
            ->groupBy('type')
            ->sortBy(fn ($cards, $type) => match ($type) {
                'Creature' => 1, 'Instant' => 2, 'Sorcery' => 3, 'Land' => 10, default => 5
            });

        $sideboard = $deckCards->filter(fn ($c) => (bool) $c->sideboard)->values();

        return [$mainDeck, $sideboard];
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

    private function buildVersionsList(
        Deck $deck,
        Carbon $from, Carbon $to,
        int $wins, int $losses,
        int $gamesWon, int $gamesLost,
        int $matchWinrate, int $gameWinrate,
        int $gamesOtpWon, int $gamesOtpLost, int $otpRate,
        int $gamesOtdWon, int $gamesOtdLost, int $otdRate,
    ): array {
        $dateScope = fn ($q) => $q->whereBetween('started_at', [$from, $to]);

        $versions = $deck->versions()
            ->withCount([
                'matches' => $dateScope,
                'matches as won_matches_count' => fn ($q) => $dateScope($q)->whereRaw('games_won > games_lost'),
                'matches as lost_matches_count' => fn ($q) => $dateScope($q)->whereRaw('games_lost > games_won'),
            ])
            ->withSum(['matches' => $dateScope], 'games_won')
            ->withSum(['matches' => $dateScope], 'games_lost')
            ->orderBy('modified_at')
            ->get();

        $latestVersionId = $versions->last()?->id;
        $versionIds = $versions->pluck('id');

        // Single batch query for OTP/OTD stats across all versions
        $otpStats = Game::query()
            ->join('game_player as gp', fn ($j) => $j->on('gp.game_id', '=', 'games.id')->where('gp.is_local', 1))
            ->join('matches as m', 'm.id', '=', 'games.match_id')
            ->whereIn('m.deck_version_id', $versionIds)
            ->whereBetween('m.started_at', [$from, $to])
            ->selectRaw('m.deck_version_id, gp.on_play, SUM(CASE WHEN games.won = 1 THEN 1 ELSE 0 END) as won, SUM(CASE WHEN games.won = 0 THEN 1 ELSE 0 END) as lost')
            ->groupBy('m.deck_version_id', 'gp.on_play')
            ->get()
            ->groupBy('deck_version_id');

        // All-time aggregate as first entry
        $result = [[
            'id' => null,
            'label' => 'All versions',
            'isCurrent' => false,
            'dateLabel' => null,
            'matchesWon' => $wins,
            'matchesLost' => $losses,
            'gamesWon' => $gamesWon,
            'gamesLost' => $gamesLost,
            'matchWinrate' => $matchWinrate,
            'gameWinrate' => $gameWinrate,
            'gamesOtpWon' => $gamesOtpWon,
            'gamesOtpLost' => $gamesOtpLost,
            'otpRate' => $otpRate,
            'gamesOtdWon' => $gamesOtdWon,
            'gamesOtdLost' => $gamesOtdLost,
            'otdRate' => $otdRate,
        ]];

        foreach ($versions as $i => $version) {
            $vOtp = $otpStats->get($version->id, collect())->first(fn ($r) => (int) $r->on_play === 1);
            $vOtd = $otpStats->get($version->id, collect())->first(fn ($r) => (int) $r->on_play === 0);

            $vOtpWon = (int) ($vOtp?->won ?? 0);
            $vOtpLost = (int) ($vOtp?->lost ?? 0);
            $vOtdWon = (int) ($vOtd?->won ?? 0);
            $vOtdLost = (int) ($vOtd?->lost ?? 0);

            $vWins = (int) ($version->won_matches_count ?? 0);
            $vLosses = (int) ($version->lost_matches_count ?? 0);
            $vGamesWon = (int) ($version->matches_sum_games_won ?? 0);
            $vGamesLost = (int) ($version->matches_sum_games_lost ?? 0);

            $nextVersion = $versions[$i + 1] ?? null;
            $dateLabel = $version->modified_at->format('M d')
                .' - '
                .($nextVersion ? $nextVersion->modified_at->format('M d') : 'now');

            $result[] = [
                'id' => $version->id,
                'label' => 'v'.($i + 1),
                'isCurrent' => $version->id === $latestVersionId,
                'dateLabel' => $dateLabel,
                'matchesWon' => $vWins,
                'matchesLost' => $vLosses,
                'gamesWon' => $vGamesWon,
                'gamesLost' => $vGamesLost,
                'matchWinrate' => round(100 * ($vWins / (($vWins + $vLosses) ?: 1))),
                'gameWinrate' => round(100 * ($vGamesWon / (($vGamesWon + $vGamesLost) ?: 1))),
                'gamesOtpWon' => $vOtpWon,
                'gamesOtpLost' => $vOtpLost,
                'otpRate' => round(100 * ($vOtpWon / (($vOtpWon + $vOtpLost) ?: 1))),
                'gamesOtdWon' => $vOtdWon,
                'gamesOtdLost' => $vOtdLost,
                'otdRate' => round(100 * ($vOtdWon / (($vOtdWon + $vOtdLost) ?: 1))),
            ];
        }

        return $result;
    }

    private function buildDeckChartData(Deck $deck, Carbon $from, Carbon $to): array
    {
        $versionIds = $deck->versions()->pluck('id');

        $results = MtgoMatch::complete()
            ->selectRaw("strftime('%Y-%m-%d', started_at) as period, SUM(CASE WHEN games_won > games_lost THEN 1 ELSE 0 END) as wins, COUNT(*) as total")
            ->whereIn('deck_version_id', $versionIds)
            ->whereNull('deleted_at')
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
