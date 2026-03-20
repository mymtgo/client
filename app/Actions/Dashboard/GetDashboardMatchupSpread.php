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
    public static function run(?int $accountId, Carbon $from, Carbon $to): array
    {
        if (! $accountId) {
            return [];
        }

        return DB::table('matches as m')
            ->join('deck_versions as dv', 'dv.id', '=', 'm.deck_version_id')
            ->join('decks as d', 'd.id', '=', 'dv.deck_id')
            ->join('match_archetypes as ma', 'ma.mtgo_match_id', '=', 'm.id')
            ->join('archetypes as a', 'a.id', '=', 'ma.archetype_id')
            ->where('d.account_id', $accountId)
            ->where('m.state', 'complete')
            ->whereBetween('m.started_at', [$from, $to])
            ->whereExists(function ($q) {
                $q->selectRaw('1')
                    ->from('game_player as gp')
                    ->join('games as g', 'g.id', '=', 'gp.game_id')
                    ->whereColumn('g.match_id', 'm.id')
                    ->whereColumn('gp.player_id', 'ma.player_id')
                    ->where('gp.is_local', 0);
            })
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
