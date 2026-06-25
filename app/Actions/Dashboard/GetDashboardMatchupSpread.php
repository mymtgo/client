<?php

namespace App\Actions\Dashboard;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class GetDashboardMatchupSpread
{
    /**
     * Account-wide matchup spread — top 5 opponent archetypes by match count.
     *
     * @return array<int, array{name: string, winrate: int, wins: int, losses: int, matches: int}>
     */
    public static function run(?int $accountId, Carbon $from, Carbon $to, ?string $format = null): array
    {
        if (! $accountId) {
            return [];
        }

        return DB::table('matches as m')
            ->join('match_archetypes as ma', function ($join) {
                $join->on('ma.mtgo_match_id', '=', 'm.id')
                    ->where('ma.is_opponent', true);
            })
            ->join('archetypes as a', 'a.id', '=', 'ma.archetype_id')
            ->where('m.account_id', $accountId)
            ->where('m.state', 'complete')
            ->when($format, fn ($q, $f) => $q->where('m.format', $f))
            ->whereBetween('m.started_at', [$from, $to])
            ->groupBy('a.id', 'a.name')
            ->selectRaw("
                a.name as name,
                COUNT(DISTINCT CASE WHEN m.outcome = 'win' THEN m.id END) as wins,
                COUNT(DISTINCT CASE WHEN m.outcome = 'loss' THEN m.id END) as losses,
                COUNT(DISTINCT m.id) as match_count,
                ROUND(
                    100.0 * COUNT(DISTINCT CASE WHEN m.outcome = 'win' THEN m.id END)
                    / NULLIF(COUNT(DISTINCT m.id), 0),
                    0
                ) as winrate
            ")
            ->orderByDesc('match_count')
            ->limit(5)
            ->get()
            ->map(fn ($r) => [
                'name' => $r->name,
                'winrate' => (int) $r->winrate,
                'wins' => (int) $r->wins,
                'losses' => (int) $r->losses,
                'matches' => (int) $r->match_count,
            ])
            ->all();
    }
}
