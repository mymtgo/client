<?php

namespace App\Actions\Dashboard;

use App\Actions\Util\Winrate;
use App\Models\MtgoMatch;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class GetPlayDrawSplit
{
    /**
     * @return array{otpWinrate: int, otdWinrate: int}
     */
    public static function run(?int $accountId, Carbon $from, Carbon $to, ?string $format = null): array
    {
        if (! $accountId) {
            return ['otpWinrate' => 0, 'otdWinrate' => 0];
        }

        $matchIds = MtgoMatch::complete()
            ->forAccount($accountId)
            ->when($format, fn ($q, $f) => $q->where('format', $f))
            ->whereBetween('started_at', [$from, $to])
            ->pluck('matches.id');

        if ($matchIds->isEmpty()) {
            return ['otpWinrate' => 0, 'otdWinrate' => 0];
        }

        $stats = DB::table('games as g')
            ->join('game_player as gp', 'gp.game_id', '=', 'g.id')
            ->whereIn('g.match_id', $matchIds)
            ->where('gp.is_local', true)
            ->selectRaw('
                SUM(CASE WHEN gp.on_play = 1 AND g.won = 1 THEN 1 ELSE 0 END) as otp_won,
                SUM(CASE WHEN gp.on_play = 1 THEN 1 ELSE 0 END) as otp_total,
                SUM(CASE WHEN gp.on_play = 0 AND g.won = 1 THEN 1 ELSE 0 END) as otd_won,
                SUM(CASE WHEN gp.on_play = 0 THEN 1 ELSE 0 END) as otd_total
            ')
            ->first();

        $otpWon = (int) $stats->otp_won;
        $otpTotal = (int) $stats->otp_total;
        $otdWon = (int) $stats->otd_won;
        $otdTotal = (int) $stats->otd_total;

        return [
            'otpWinrate' => Winrate::percentage($otpWon, $otpTotal - $otpWon),
            'otdWinrate' => Winrate::percentage($otdWon, $otdTotal - $otdWon),
        ];
    }
}
