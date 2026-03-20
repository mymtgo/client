<?php

namespace App\Actions\Dashboard;

use App\Models\MtgoMatch;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class GetPlayDrawSplit
{
    /**
     * @return array{otpWinrate: int, otdWinrate: int}
     */
    public static function run(?int $accountId, Carbon $from, Carbon $to): array
    {
        $matchIds = MtgoMatch::complete()
            ->when($accountId, fn ($q, $id) => $q->whereHas('deckVersion', fn ($q2) => $q2->whereHas('deck', fn ($q3) => $q3->where('account_id', $id))))
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

        $otpTotal = (int) $stats->otp_total;
        $otdTotal = (int) $stats->otd_total;

        return [
            'otpWinrate' => $otpTotal > 0 ? (int) round(100 * (int) $stats->otp_won / $otpTotal) : 0,
            'otdWinrate' => $otdTotal > 0 ? (int) round(100 * (int) $stats->otd_won / $otdTotal) : 0,
        ];
    }
}
