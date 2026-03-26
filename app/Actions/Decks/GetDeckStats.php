<?php

namespace App\Actions\Decks;

use App\Actions\Util\Winrate;
use App\Enums\MatchOutcome;
use App\Models\Deck;
use App\Models\Game;
use App\Models\League;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class GetDeckStats
{
    /**
     * Compute match, game, and OTP/OTD stats for a deck within a date range.
     *
     * @return array{wins: int, losses: int, gamesWon: int, gamesLost: int, matchWinrate: int, gameWinrate: int, otpWon: int, otpLost: int, otpRate: int, otdWon: int, otdLost: int, otdRate: int, trophies: int, allMatchIds: Collection}
     */
    public static function run(Deck $deck, Carbon $from, Carbon $to): array
    {
        $matchesQuery = $deck->matches()->select('matches.*')->where('state', 'complete')
            ->whereBetween('started_at', [$from, $to]);

        $wins = $matchesQuery->clone()->where('outcome', MatchOutcome::Win)->count();
        $losses = $matchesQuery->clone()->where('outcome', MatchOutcome::Loss)->count();
        $gamesWon = (int) $matchesQuery->clone()->withCount(['games as games_won_sum' => fn ($q) => $q->where('won', true)])->get()->sum('games_won_sum');
        $gamesLost = (int) $matchesQuery->clone()->withCount(['games as games_lost_sum' => fn ($q) => $q->where('won', false)])->get()->sum('games_lost_sum');

        $matchIds = $matchesQuery->clone()->select('matches.id')->pluck('matches.id');
        $matchGamesQuery = Game::whereHas('match', fn ($q) => $q->whereIn('match_id', $matchIds));

        $gamesotp = $matchGamesQuery->clone()->whereHas('localPlayers', fn ($q) => $q->where('on_play', 1));
        $gamesotd = $matchGamesQuery->clone()->whereHas('localPlayers', fn ($q) => $q->where('on_play', 0));

        $otpWon = $gamesotp->clone()->where('won', 1)->count();
        $otpLost = $gamesotp->clone()->where('won', 0)->count();
        $otdWon = $gamesotd->clone()->where('won', 1)->count();
        $otdLost = $gamesotd->clone()->where('won', 0)->count();

        $totalMatches = $wins + $losses;

        // All match IDs for this deck (full history, not just date range)
        $allMatchIds = $deck->matches()->select('matches.id')->where('state', 'complete')->pluck('matches.id');

        // Trophies = completed real leagues where all 5 matches were won
        $trophies = League::whereHas('matches', fn ($q) => $q->whereIn('matches.id', $allMatchIds))
            ->withCount([
                'matches as won_count' => fn ($q) => $q->whereIn('matches.id', $allMatchIds)->where('outcome', 'win'),
                'matches as total_count' => fn ($q) => $q->whereIn('matches.id', $allMatchIds),
            ])
            ->get()
            ->filter(fn ($l) => $l->total_count === 5 && $l->won_count === 5)
            ->count();

        return [
            'wins' => $wins,
            'losses' => $losses,
            'gamesWon' => $gamesWon,
            'gamesLost' => $gamesLost,
            'matchWinrate' => Winrate::percentage($wins, $losses),
            'gameWinrate' => Winrate::percentage($gamesWon, $gamesLost),
            'otpWon' => $otpWon,
            'otpLost' => $otpLost,
            'otpRate' => Winrate::percentage($otpWon, $otpLost),
            'otdWon' => $otdWon,
            'otdLost' => $otdLost,
            'otdRate' => Winrate::percentage($otdWon, $otdLost),
            'trophies' => $trophies,
            'allMatchIds' => $allMatchIds,
        ];
    }
}
