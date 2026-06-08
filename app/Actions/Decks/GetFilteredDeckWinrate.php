<?php

namespace App\Actions\Decks;

use App\Data\Front\DeckWinrateData;
use App\Models\Deck;
use App\Models\DeckVersion;
use Illuminate\Support\Facades\DB;

class GetFilteredDeckWinrate
{
    public static function run(
        Deck $deck,
        ?DeckVersion $deckVersion = null,
        ?int $opponentArchetypeId = null,
        ?bool $onPlay = null,
        ?bool $isPostboard = null,
    ): DeckWinrateData {
        $versionIds = $deckVersion
            ? [$deckVersion->id]
            : $deck->versions()->pluck('id')->all();

        if (empty($versionIds)) {
            return new DeckWinrateData(wins: 0, games: 0, rate: 0.5);
        }

        $query = DB::table('games as g')
            ->join('matches as m', 'm.id', '=', 'g.match_id')
            ->whereIn('m.deck_version_id', $versionIds);

        if ($isPostboard !== null) {
            $query->whereExists(function ($sub) use ($isPostboard): void {
                $sub->select(DB::raw(1))
                    ->from('card_game_stats as cgs')
                    ->whereRaw('cgs.game_id = g.id')
                    ->where('cgs.is_postboard', $isPostboard);
            });
        }

        if ($onPlay !== null) {
            $query->whereExists(function ($sub) use ($onPlay): void {
                $sub->select(DB::raw(1))
                    ->from('game_player as local_gp')
                    ->whereRaw('local_gp.game_id = g.id')
                    ->where('local_gp.is_local', true)
                    ->where('local_gp.on_play', $onPlay);
            });
        }

        if ($opponentArchetypeId) {
            $query->whereExists(function ($sub) use ($opponentArchetypeId): void {
                $sub->select(DB::raw(1))
                    ->from('match_archetypes as ma')
                    ->join('game_player as opp_gp', function ($join): void {
                        $join->on('opp_gp.game_id', '=', 'g.id')
                            ->on('opp_gp.player_id', '=', 'ma.player_id');
                    })
                    ->whereRaw('ma.mtgo_match_id = g.match_id')
                    ->where('ma.archetype_id', $opponentArchetypeId)
                    ->where('opp_gp.is_local', false);
            });
        }

        $row = $query
            ->selectRaw('COUNT(*) as games, SUM(CASE WHEN g.won THEN 1 ELSE 0 END) as wins')
            ->first();

        $games = (int) ($row->games ?? 0);
        $wins = (int) ($row->wins ?? 0);
        $rate = $games > 0 ? $wins / $games : 0.5;

        return new DeckWinrateData(wins: $wins, games: $games, rate: $rate);
    }
}
