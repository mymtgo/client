<?php

namespace App\Actions\Decks;

use App\Models\Deck;
use App\Models\Game;
use Carbon\Carbon;

class GetDeckStats
{
    /**
     * Compute match, game, and OTP/OTD stats for a deck within a date range.
     *
     * @return array{wins: int, losses: int, gamesWon: int, gamesLost: int, matchWinrate: int, gameWinrate: int, otpWon: int, otpLost: int, otpRate: int, otdWon: int, otdLost: int, otdRate: int, trophies: int, allMatchIds: \Illuminate\Support\Collection}
     */
    public static function run(Deck $deck, Carbon $from, Carbon $to): array
    {
        $matchesQuery = $deck->matches()->select('matches.*')->where('state', 'complete')
            ->whereBetween('started_at', [$from, $to]);

        $wins = $matchesQuery->clone()->whereRaw('games_won > games_lost')->count();
        $losses = $matchesQuery->clone()->whereRaw('games_won < games_lost')->count();
        $gamesWon = (int) $matchesQuery->clone()->sum('games_won');
        $gamesLost = (int) $matchesQuery->clone()->sum('games_lost');

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
        $trophies = \App\Models\League::whereHas('matches', fn ($q) => $q->whereIn('matches.id', $allMatchIds))
            ->withCount([
                'matches as won_count' => fn ($q) => $q->whereIn('matches.id', $allMatchIds)->whereRaw('games_won > games_lost'),
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
            'matchWinrate' => round(100 * ($wins / ($totalMatches ?: 1))),
            'gameWinrate' => round(100 * ($gamesWon / (($gamesWon + $gamesLost) ?: 1))),
            'otpWon' => $otpWon,
            'otpLost' => $otpLost,
            'otpRate' => round(100 * ($otpWon / (($otpWon + $otpLost) ?: 1))),
            'otdWon' => $otdWon,
            'otdLost' => $otdLost,
            'otdRate' => round(100 * ($otdWon / (($otdWon + $otdLost) ?: 1))),
            'trophies' => $trophies,
            'allMatchIds' => $allMatchIds,
        ];
    }
}
