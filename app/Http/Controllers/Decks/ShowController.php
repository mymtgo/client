<?php

namespace App\Http\Controllers\Decks;

use App\Actions\Cards\GetCards;
use App\Actions\Decks\GetArchetypeMatchupSpread;
use App\Data\Front\CardData;
use App\Data\Front\DeckData;
use App\Data\Front\LeagueData;
use App\Data\Front\MatchData;
use App\Http\Controllers\Controller;
use App\Models\Deck;
use App\Models\Game;
use App\Models\League;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class ShowController extends Controller
{
    public function __invoke(string $id, Request $request)
    {
        $deck = Deck::with(['matches.opponentArchetypes.archetype'])->withCount(['wonMatches', 'lostMatches', 'matches'])->find($id);

        if (! $deck) {
            return redirect()->route('home');
        }

        $timeframe = $request->input('timeframe', 'week');

        $start = now()->subDays(7)->startOfDay();
        $end = now()->endOfDay();

        $deckVersion = $deck->latestVersion;

        $cards = GetCards::run($deckVersion->cards);

        $deckCards = collect($deckVersion->cards)->map(function ($card) use ($cards) {
            $cardModel = $cards->first(
                fn ($c) => $c->oracle_id == $card['oracle_id']
            );

            if (! $cardModel) {
                return null;
            }

            $cardModel->sideboard = $card['sideboard'] === 'true';
            $cardModel->quantity = $card['quantity'];

            return CardData::from($cardModel);
        })->sortBy('type')->values()->filter();

        $mainDeck = $deckCards->filter(
            fn ($card) => ! $card->sideboard,
        )->groupBy('type')->sortBy(function ($cards, $type) {

            if ($type == 'Creature') {
                return 1;
            }

            if ($type == 'Instant') {
                return 2;
            }

            if ($type == 'Sorcery') {
                return 3;
            }

            if ($type == 'Land') {
                return 10;
            }

            return 5;
        });

        $sideboard = $deckCards->filter(
            fn ($card) => (bool) $card->sideboard,
        );

        /**
         * Get winrate for the timeframe.
         */
        $matchChartData = $deck->matches()
            ->select(
                'started_at',
                DB::raw('COUNT(*) total_matches'),
                DB::raw('SUM(CASE WHEN games_won > games_lost THEN 1 ELSE 0 END) as match_wins'),
                DB::raw('SUM(CASE WHEN games_won < games_lost THEN 1 ELSE 0 END) as match_losses'),
                DB::raw('ROUND(
                    100.0 * SUM(CASE WHEN games_won > games_lost THEN 1 ELSE 0 END)
                    / NULLIF(COUNT(*), 0),
                    0
                ) as match_winrate_pct')
            )
            ->groupByRaw("STRFTIME('%d-%m', started_at)")
            ->whereBetween('started_at', [
                $start,
                $end,
            ])
            ->get();

        $period = CarbonPeriod::create($start, $end, '1 day');

        $chartData = [];

        foreach ($period as $date) {
            $match = $matchChartData->first(
                fn ($match) => $match->started_at->format('Y-m-d') === $date->format('Y-m-d')
            );

            $chartData[] = [
                'date' => $date->format('Y-m-d'),
                'total_matches' => $match?->total_matches ?: 0,
                'match_wins' => $match?->match_wins ?: 0,
                'match_losses' => $match?->match_losses ?: 0,
                'winrate' => $match?->match_winrate_pct ?: 0,
            ];
        }

        $matchesQuery = $deck->matches()->whereBetween('started_at', [
            $start,
            $end,
        ])->whereNull('deleted_at');

        $leagues = League::with(['matches.opponentArchetypes.archetype'])
            ->whereHas('matches', fn ($query) => $query->whereIn('matches.id', $matchesQuery->pluck('matches.id')))
            ->with('matches')->get();

        $losses = $matchesQuery->clone()->whereRaw('games_won < games_lost')->count();
        $wins = $matchesQuery->clone()->whereRaw('games_won > games_lost')->count();
        $gamesWon = $matchesQuery->clone()->sum('games_won');
        $gamesLost = $matchesQuery->clone()->sum('games_lost');

        $matchGamesQuery = Game::whereHas(
            'match',
            fn ($query) => $query->whereIn(
                'match_id',
                $deck->matches()->select('matches.id')->get()->pluck('id')
            )
        );

        $gamesotp = $matchGamesQuery->clone()->whereHas(
            'localPlayers',
            fn ($query) => $query->where('on_play', 1)
        );

        $gamesotpWon = $gamesotp->clone()->where('won', 1)->count();
        $gamesotpLost = $gamesotp->clone()->where('won', 0)->count();

        $gamesotd = $matchGamesQuery->clone()->whereHas(
            'localPlayers',
            fn ($query) => $query->where('on_play', 0)
        );

        $gamesotdWon = $gamesotd->clone()->where('won', 1)->count();
        $gamesotdLost = $gamesotd->clone()->where('won', 0)->count();

        $matchWinrate = round(100 * ($wins / ($matchesQuery->count() ?: 1)));
        $gameWinrate = round(100 * ($gamesWon / (($gamesWon + $gamesLost) ?: 1)));
        $otpRate = round(100 * ($gamesotp->count() / (($gamesotp->count() + $gamesotd->count()) ?: 1)));

        return Inertia::render('decks/Show', [
            'deck' => DeckData::from($deck),
            'matchupSpread' => GetArchetypeMatchupSpread::run($deck, $start, $end),
            'maindeck' => $mainDeck,
            'sideboard' => $sideboard,
            'matchesWon' => $wins,
            'matchesLost' => $losses,
            'gamesWon' => $gamesWon,
            'gamesLost' => $gamesLost,
            'matchWinrate' => $matchWinrate,
            'gameWinrate' => $gameWinrate,
            'gamesOtd' => $gamesotd->count(),
            'gamesOtdWon' => $gamesotdWon,
            'gamesOtdLost' => $gamesotdLost,
            'gamesOtp' => $gamesotp->count(),
            'gamesOtpWon' => $gamesotpWon,
            'gamesOtpLost' => $gamesotpLost,
            'otpRate' => $otpRate,
            'matchChartData' => $chartData,
            'matches' => MatchData::collect(
                $deck->matches()->whereBetween('started_at', [$start, $end])->with(['opponentArchetypes.archetype'])->orderByDesc('started_at')->paginate(50)
            ),
            'leagues' => LeagueData::collect($leagues),
        ]);
    }
}
