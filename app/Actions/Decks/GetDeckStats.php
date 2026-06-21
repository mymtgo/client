<?php

namespace App\Actions\Decks;

use App\Actions\Util\Winrate;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\League;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class GetDeckStats
{
    /**
     * Compute match, game, and OTP/OTD stats for a deck within a date range.
     *
     * @return array{wins: int, losses: int, draws: int, total: int, gamesWon: int, gamesLost: int, matchWinrate: int, gameWinrate: int, otpWon: int, otpLost: int, otpRate: int, otdWon: int, otdLost: int, otdRate: int, trophies: int, allMatchIds: Collection}
     */
    public static function run(Deck $deck, Carbon $from, Carbon $to, ?DeckVersion $deckVersion = null): array
    {
        $matchesQuery = $deck->matches()->select('matches.*')
            ->whereBetween('matches.started_at', [$from, $to])
            ->when($deckVersion, fn ($q) => $q->where('matches.deck_version_id', $deckVersion->id));

        // Query 1: Match-level outcome counts. Kept off the games table so
        // gameless imported matches are still counted; draws/unknown outcomes
        // fall out of wins/losses but remain in the total.
        $matchCounts = $matchesQuery->clone()
            ->toBase()
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN matches.outcome = 'win' THEN 1 ELSE 0 END) as wins,
                SUM(CASE WHEN matches.outcome = 'loss' THEN 1 ELSE 0 END) as losses
            ")
            ->first();

        $total = (int) ($matchCounts->total ?? 0);
        $wins = (int) ($matchCounts->wins ?? 0);
        $losses = (int) ($matchCounts->losses ?? 0);
        $draws = max(0, $total - $wins - $losses);

        // Query 2: Game counts from real game rows.
        $gameRowCounts = $matchesQuery->clone()
            ->toBase()
            ->join('games', 'games.match_id', '=', 'matches.id')
            ->whereNotNull('games.won')
            ->selectRaw('
                SUM(CASE WHEN games.won = 1 THEN 1 ELSE 0 END) as games_won,
                SUM(CASE WHEN games.won = 0 THEN 1 ELSE 0 END) as games_lost
            ')
            ->first();

        // Gameless imported matches have match-level games_won/games_lost but
        // zero game rows; fold their tallies into the game totals.
        $gamelessCounts = $matchesQuery->clone()
            ->toBase()
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('games')->whereColumn('games.match_id', 'matches.id'))
            ->selectRaw('SUM(COALESCE(matches.games_won, 0)) as games_won, SUM(COALESCE(matches.games_lost, 0)) as games_lost')
            ->first();

        $gamesWon = (int) ($gameRowCounts->games_won ?? 0) + (int) ($gamelessCounts->games_won ?? 0);
        $gamesLost = (int) ($gameRowCounts->games_lost ?? 0) + (int) ($gamelessCounts->games_lost ?? 0);

        // Query 3: OTP/OTD stats
        $otpStats = $matchesQuery->clone()
            ->toBase()
            ->join('games', 'games.match_id', '=', 'matches.id')
            ->join('game_player', function ($join) {
                $join->on('game_player.game_id', '=', 'games.id')
                    ->where('game_player.is_local', true);
            })
            ->selectRaw('
                SUM(CASE WHEN game_player.on_play = 1 AND games.won = 1 THEN 1 ELSE 0 END) as otp_won,
                SUM(CASE WHEN game_player.on_play = 1 AND games.won = 0 THEN 1 ELSE 0 END) as otp_lost,
                SUM(CASE WHEN game_player.on_play = 0 AND games.won = 1 THEN 1 ELSE 0 END) as otd_won,
                SUM(CASE WHEN game_player.on_play = 0 AND games.won = 0 THEN 1 ELSE 0 END) as otd_lost
            ')
            ->first();

        $otpWon = (int) ($otpStats->otp_won ?? 0);
        $otpLost = (int) ($otpStats->otp_lost ?? 0);
        $otdWon = (int) ($otpStats->otd_won ?? 0);
        $otdLost = (int) ($otpStats->otd_lost ?? 0);

        // All match IDs for full history (used by callers for league/archetype queries)
        $allMatchIds = $deck->matches()->select('matches.id')->where('state', 'complete')
            ->when($deckVersion, fn ($q) => $q->where('deck_version_id', $deckVersion->id))
            ->pluck('matches.id');

        // Query 3: Trophies
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
            'draws' => $draws,
            'total' => $total,
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
