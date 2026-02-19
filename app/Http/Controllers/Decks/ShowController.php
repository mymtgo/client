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

        $period = $request->input('period', 'all_time');
        [$from, $to] = $this->resolveDateRange($period);

        $matchesQuery = $deck->matches()->select('matches.*')->whereNull('deleted_at');
        if ($from && $to) {
            $matchesQuery->whereBetween('started_at', [$from, $to]);
        }

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

        // Captured for use inside deferred closures
        $matchIdsInRange = $matchIds;

        return Inertia::render('decks/Show', [
            // ── Eager: needed for the initial render ─────────────────────────
            'deck' => DeckData::from($deck),
            'period' => $period,
            'chartGranularity' => in_array($period, ['this_week', 'this_month']) ? 'daily' : 'monthly',
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
            'chartData' => fn () => $this->buildDeckChartData($deck, $from, $to, $period),
            'versions' => fn () => $this->buildVersionsList($deck, $wins, $losses, $gamesWon, $gamesLost, $matchWinrate, $gameWinrate, $gamesotpWon, $gamesotpLost, $otpRate, $gamesotdWon, $gamesotdLost, $otdRate),

            // ── Deferred: auto-loaded in background after initial render ─────
            // Default group — matches tab (loads immediately after paint)
            'matches' => Inertia::defer(fn () => MatchData::collect(
                $matchesQuery->clone()
                    ->with(['opponentArchetypes.archetype', 'opponentArchetypes.player', 'league'])
                    ->orderByDesc('started_at')
                    ->paginate(50)
            )),
            'archetypes' => Inertia::defer(
                fn () => ArchetypeData::collect(Archetype::orderBy('name')->get())
            ),

            // Tabs group — matchups + leagues load together
            'matchupSpread' => Inertia::defer(
                fn () => GetArchetypeMatchupSpread::run($deck, $from, $to),
                'tabs'
            ),
            'leagues' => Inertia::defer(function () use ($matchIdsInRange) {
                $leagues = League::with(['matches' => fn ($q) => $q
                    ->whereIn('matches.id', $matchIdsInRange)
                    ->with(['opponentArchetypes.archetype', 'opponentArchetypes.player', 'league'])])
                    ->whereHas('matches', fn ($q) => $q->whereIn('matches.id', $matchIdsInRange))
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

    private function resolveDateRange(string $period): array
    {
        return match ($period) {
            'this_week' => [now()->startOfWeek(), now()->endOfDay()],
            'this_month' => [now()->startOfMonth(), now()->endOfDay()],
            'this_year' => [now()->startOfYear(), now()->endOfDay()],
            default => [null, null],
        };
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
        int $wins, int $losses,
        int $gamesWon, int $gamesLost,
        int $matchWinrate, int $gameWinrate,
        int $gamesOtpWon, int $gamesOtpLost, int $otpRate,
        int $gamesOtdWon, int $gamesOtdLost, int $otdRate,
    ): array {
        $versions = $deck->versions()
            ->withCount([
                'matches',
                'matches as won_matches_count' => fn ($q) => $q->whereRaw('games_won > games_lost'),
                'matches as lost_matches_count' => fn ($q) => $q->whereRaw('games_lost > games_won'),
            ])
            ->withSum('matches', 'games_won')
            ->withSum('matches', 'games_lost')
            ->orderBy('modified_at')
            ->get();

        $latestVersionId = $versions->last()?->id;
        $versionIds = $versions->pluck('id');

        // Single batch query for OTP/OTD stats across all versions
        $otpStats = Game::query()
            ->join('game_player as gp', fn ($j) => $j->on('gp.game_id', '=', 'games.id')->where('gp.is_local', 1))
            ->join('matches as m', 'm.id', '=', 'games.match_id')
            ->whereIn('m.deck_version_id', $versionIds)
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
                .' – '
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

    private function buildDeckChartData(Deck $deck, ?Carbon $from, ?Carbon $to, string $periodKey): array
    {
        $versionIds = $deck->versions()->pluck('id');
        $isDaily = in_array($periodKey, ['this_week', 'this_month']);

        $groupExpr = $isDaily
            ? "strftime('%Y-%m-%d', started_at)"
            : "strftime('%Y-%m', started_at)";

        $query = MtgoMatch::query()
            ->selectRaw("{$groupExpr} as period, SUM(CASE WHEN games_won > games_lost THEN 1 ELSE 0 END) as wins, COUNT(*) as total")
            ->whereIn('deck_version_id', $versionIds)
            ->whereNull('deleted_at');

        if ($from && $to) {
            $query->whereBetween('started_at', [$from, $to]);
        }

        $results = $query
            ->groupBy('period')
            ->orderBy('period')
            ->get()
            ->keyBy('period');

        $firstMatch = MtgoMatch::whereIn('deck_version_id', $versionIds)
            ->whereNull('deleted_at')
            ->orderBy('started_at')
            ->first();

        if (! $firstMatch && $results->isEmpty()) {
            return [];
        }

        if ($isDaily) {
            $startDate = $from
                ? $from->copy()->startOfDay()
                : Carbon::parse($firstMatch->started_at)->startOfDay();
            $endDate = $to ? $to->copy()->startOfDay() : now()->startOfDay();
            $carbonPeriod = CarbonPeriod::between($startDate, $endDate)->days();
        } else {
            $startDate = $from
                ? $from->copy()->startOfMonth()
                : Carbon::parse($firstMatch->started_at)->startOfMonth();
            $endDate = $to ? $to->copy()->startOfMonth() : now()->startOfMonth();
            $carbonPeriod = CarbonPeriod::between($startDate, $endDate)->months();
        }

        return collect($carbonPeriod)->map(function (Carbon $point) use ($results, $isDaily) {
            $key = $isDaily ? $point->format('Y-m-d') : $point->format('Y-m');
            $row = $results->get($key);

            return [
                'date' => $isDaily ? $key : $key.'-01',
                'winrate' => $row ? (string) round($row->wins / $row->total * 100) : null,
            ];
        })->toArray();
    }
}
