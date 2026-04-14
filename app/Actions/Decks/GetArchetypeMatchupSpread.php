<?php

namespace App\Actions\Decks;

use App\Models\Deck;
use App\Models\DeckVersion;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class GetArchetypeMatchupSpread
{
    public static function run(Deck $deck, ?Carbon $from, ?Carbon $to, ?DeckVersion $deckVersion = null)
    {
        $deckVersions = $deckVersion
            ? collect([$deckVersion->id])
            : $deck->versions()->pluck('id');

        // Step 1: Get distinct (archetype_id, match_id) pairs (unchanged)
        $pairsQuery = DB::table('matches as m')
            ->join('match_archetypes as ma', 'ma.mtgo_match_id', '=', 'm.id')
            ->join('archetypes as a', 'a.id', '=', 'ma.archetype_id')
            ->whereIn('m.deck_version_id', $deckVersions->toArray())
            ->where('m.state', 'complete')
            ->whereExists(function ($q) {
                $q->selectRaw('1')
                    ->from('game_player as gp')
                    ->join('games as g', 'g.id', '=', 'gp.game_id')
                    ->whereColumn('g.match_id', 'm.id')
                    ->whereColumn('gp.player_id', 'ma.player_id')
                    ->where('gp.is_local', 0);
            })
            ->selectRaw('DISTINCT a.id as archetype_id, a.name as archetype_name, a.color_identity, m.id as match_id, m.outcome');

        if ($from && $to) {
            $pairsQuery->whereBetween('m.started_at', [$from, $to]);
        }

        // Step 2: Pre-compute game stats per match (replaces correlated subqueries)
        $gameStatsQuery = DB::table('games as g')
            ->leftJoin('game_player as gp', function ($join) {
                $join->on('gp.game_id', '=', 'g.id')
                    ->where('gp.is_local', true);
            })
            ->groupBy('g.match_id')
            ->selectRaw('
                g.match_id,
                SUM(CASE WHEN g.won = 1 THEN 1 ELSE 0 END) as games_won,
                SUM(CASE WHEN g.won = 0 THEN 1 ELSE 0 END) as games_lost,
                SUM(CASE WHEN g.won IS NOT NULL THEN 1 ELSE 0 END) as total_games,
                SUM(CASE WHEN gp.on_play = 1 AND g.won = 1 THEN 1 ELSE 0 END) as otp_won,
                SUM(CASE WHEN gp.on_play = 1 AND g.won IS NOT NULL THEN 1 ELSE 0 END) as otp_total,
                SUM(CASE WHEN g.turn_count IS NOT NULL THEN g.turn_count ELSE 0 END) as total_turns,
                SUM(CASE WHEN g.turn_count IS NOT NULL THEN 1 ELSE 0 END) as games_with_turns
            ');

        // Step 3: Join pairs with pre-computed game stats and aggregate
        return DB::query()
            ->fromSub($pairsQuery, 'pairs')
            ->joinSub($gameStatsQuery, 'gs', 'gs.match_id', '=', 'pairs.match_id')
            ->groupBy('pairs.archetype_id', 'pairs.archetype_name', 'pairs.color_identity')
            ->selectRaw("
                pairs.archetype_id,
                pairs.archetype_name,
                pairs.color_identity,

                SUM(gs.games_won) as games_won,
                SUM(gs.games_lost) as games_lost,
                SUM(gs.total_games) as total_games,

                SUM(CASE WHEN pairs.outcome = 'win' THEN 1 ELSE 0 END) as match_wins,
                SUM(CASE WHEN pairs.outcome = 'loss' THEN 1 ELSE 0 END) as match_losses,
                COUNT(*) as match_count,

                ROUND(
                    100.0 * SUM(CASE WHEN pairs.outcome = 'win' THEN 1 ELSE 0 END)
                    / NULLIF(COUNT(*), 0), 0
                ) as match_winrate_pct,

                ROUND(
                    100.0 * SUM(gs.games_won)
                    / NULLIF(SUM(gs.total_games), 0), 0
                ) as game_winrate_pct,

                ROUND(
                    100.0 * SUM(gs.otp_won)
                    / NULLIF(SUM(gs.otp_total), 0), 0
                ) as otp_winrate_pct,

                CAST(SUM(gs.total_turns) AS REAL)
                / NULLIF(SUM(gs.games_with_turns), 0) as avg_turns
            ")
            ->orderByDesc('game_winrate_pct')
            ->get()
            ->map(fn ($r) => [
                'archetype_id' => (int) $r->archetype_id,
                'name' => $r->archetype_name,
                'color_identity' => $r->color_identity,

                'match_winrate' => (int) $r->match_winrate_pct,
                'game_winrate' => (int) $r->game_winrate_pct,
                'matches' => (int) $r->match_count,
                'match_record' => ((int) $r->match_wins).' - '.((int) $r->match_losses),
                'game_record' => ((int) $r->games_won).' - '.((int) $r->games_lost),

                'match_wins' => (int) $r->match_wins,
                'match_losses' => (int) $r->match_losses,
                'games_won' => (int) $r->games_won,
                'games_lost' => (int) $r->games_lost,
                'total_games' => (int) $r->total_games,

                'otp_winrate' => (int) ($r->otp_winrate_pct ?? 0),
                'avg_turns' => $r->avg_turns !== null ? round((float) $r->avg_turns, 1) : null,
            ]);
    }
}
