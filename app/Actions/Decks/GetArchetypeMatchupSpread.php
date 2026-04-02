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

        $query = DB::table('matches as m')
            ->join('match_archetypes as ma', 'ma.mtgo_match_id', '=', 'm.id')
            ->join('archetypes as a', 'a.id', '=', 'ma.archetype_id')
            ->whereIn('m.deck_version_id', $deckVersions->toArray())
            ->where('m.state', 'complete');

        if ($from && $to) {
            $query->whereBetween('m.started_at', [$from, $to]);
        }

        return $query
            ->whereExists(function ($q) {
                $q->selectRaw('1')
                    ->from('game_player as gp')
                    ->join('games as g', 'g.id', '=', 'gp.game_id')
                    ->whereColumn('g.match_id', 'm.id')
                    ->whereColumn('gp.player_id', 'ma.player_id')
                    ->where('gp.is_local', 0);
            })
            ->groupBy('a.id', 'a.name')
            ->selectRaw('
        a.id as archetype_id,
        a.name as archetype_name,
        a.color_identity as color_identity,

        SUM((SELECT COUNT(*) FROM games g WHERE g.match_id = m.id AND g.won = 1)) as games_won,
        SUM((SELECT COUNT(*) FROM games g WHERE g.match_id = m.id AND g.won = 0)) as games_lost,
        SUM((SELECT COUNT(*) FROM games g WHERE g.match_id = m.id AND g.won IS NOT NULL)) as total_games,

        COUNT(DISTINCT CASE WHEN m.outcome = \'win\' THEN m.id END) as match_wins,
        COUNT(DISTINCT CASE WHEN m.outcome = \'loss\' THEN m.id END) as match_losses,
        COUNT(DISTINCT m.id) as match_count,

        ROUND(
            100.0 * COUNT(DISTINCT CASE WHEN m.outcome = \'win\' THEN m.id END)
            / NULLIF(COUNT(DISTINCT m.id), 0),
            0
        ) as match_winrate_pct,

        ROUND(
            100.0 * SUM((SELECT COUNT(*) FROM games g WHERE g.match_id = m.id AND g.won = 1))
            / NULLIF(SUM((SELECT COUNT(*) FROM games g WHERE g.match_id = m.id AND g.won IS NOT NULL)), 0),
            0
        ) as game_winrate_pct
    ')
            ->orderByDesc('game_winrate_pct')
            ->get()
            ->map(fn ($r) => [
                'archetype_id' => (int) $r->archetype_id,
                'name' => $r->archetype_name,
                'color_identity' => $r->color_identity,

                // UI-friendly
                'match_winrate' => (int) $r->match_winrate_pct,
                'game_winrate' => (int) $r->game_winrate_pct,
                'matches' => (int) $r->match_count,
                'match_record' => ((int) $r->match_wins).' - '.((int) $r->match_losses),
                'game_record' => ((int) $r->games_won).' - '.((int) $r->games_lost),

                // for bars if you want them
                'match_wins' => (int) $r->match_wins,
                'match_losses' => (int) $r->match_losses,
                'games_won' => (int) $r->games_won,
                'games_lost' => (int) $r->games_lost,
                'total_games' => (int) $r->total_games,
            ]);
    }
}
