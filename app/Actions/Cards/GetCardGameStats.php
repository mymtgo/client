<?php

namespace App\Actions\Cards;

use App\Models\Archetype;
use App\Models\DeckVersion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class GetCardGameStats
{
    public static function run(DeckVersion $deckVersion, ?int $opponentArchetypeId = null, ?bool $onPlay = null, ?bool $isPostboard = null): Collection
    {
        $sideboardOracles = collect($deckVersion->cards)
            ->filter(fn ($card) => $card['sideboard'] === 'true' || $card['sideboard'] === true)
            ->pluck('oracle_id')
            ->flip();

        $query = DB::table('card_game_stats as cgs')
            ->join(DB::raw('(SELECT oracle_id, name, color_identity, type, image FROM cards WHERE oracle_id IS NOT NULL GROUP BY oracle_id) as c'), 'c.oracle_id', '=', 'cgs.oracle_id')
            ->where('cgs.deck_version_id', $deckVersion->id);

        $query->when($isPostboard !== null, fn ($q) => $q->where('cgs.is_postboard', $isPostboard));

        if ($opponentArchetypeId) {
            $query->join('games as g', 'g.id', '=', 'cgs.game_id')
                ->join('match_archetypes as ma', function ($join) use ($opponentArchetypeId) {
                    $join->on('ma.mtgo_match_id', '=', 'g.match_id')
                        ->where('ma.archetype_id', $opponentArchetypeId);
                })
                ->whereExists(function ($sub) {
                    $sub->select(DB::raw(1))
                        ->from('game_player as gp')
                        ->whereRaw('gp.game_id = g.id')
                        ->whereRaw('gp.player_id = ma.player_id')
                        ->where('gp.is_local', false);
                });
        }

        if ($onPlay !== null) {
            // Join games if not already joined by archetype filter
            if (! $opponentArchetypeId) {
                $query->join('games as g', 'g.id', '=', 'cgs.game_id');
            }

            $query->whereExists(function ($sub) use ($onPlay) {
                $sub->select(DB::raw(1))
                    ->from('game_player as local_gp')
                    ->whereRaw('local_gp.game_id = g.id')
                    ->where('local_gp.is_local', true)
                    ->where('local_gp.on_play', $onPlay);
            });
        }

        return $query->groupBy('cgs.oracle_id')
            ->selectRaw('
                c.name,
                cgs.oracle_id,
                c.color_identity,
                c.type,
                c.image,
                COUNT(*) as total_games,
                SUM(cgs.quantity) as total_possible,
                SUM(cgs.kept) as total_kept,
                SUM(CASE WHEN cgs.kept > 0 AND cgs.won THEN 1 ELSE 0 END) as kept_won,
                SUM(CASE WHEN cgs.kept > 0 AND NOT cgs.won THEN 1 ELSE 0 END) as kept_lost,
                SUM(cgs.seen) as total_seen,
                SUM(CASE WHEN cgs.seen > 0 AND cgs.won THEN 1 ELSE 0 END) as seen_won,
                SUM(CASE WHEN cgs.seen > 0 AND NOT cgs.won THEN 1 ELSE 0 END) as seen_lost,
                SUM(CASE WHEN cgs.is_postboard THEN 1 ELSE 0 END) as postboard_games,
                SUM(CASE WHEN cgs.sided_out THEN 1 ELSE 0 END) as sided_out_games
            ')
            ->orderBy('c.type')
            ->orderBy('c.name')
            ->get()
            ->map(fn ($row) => [
                'name' => $row->name,
                'oracleId' => $row->oracle_id,
                'colorIdentity' => $row->color_identity,
                'type' => $row->type,
                'image' => $row->image,
                'isSideboard' => $sideboardOracles->has($row->oracle_id),
                'totalGames' => (int) $row->total_games,
                'totalPossible' => (int) $row->total_possible,
                'totalKept' => (int) $row->total_kept,
                'keptWon' => (int) $row->kept_won,
                'keptLost' => (int) $row->kept_lost,
                'totalSeen' => (int) $row->total_seen,
                'seenWon' => (int) $row->seen_won,
                'seenLost' => (int) $row->seen_lost,
                'postboardGames' => (int) $row->postboard_games,
                'sidedOutGames' => (int) $row->sided_out_games,
            ]);
    }

    /**
     * Get archetypes that have card_game_stats data for this deck version.
     */
    public static function availableArchetypes(DeckVersion $deckVersion): Collection
    {
        return Archetype::query()
            ->whereHas('matchArchetypes', function ($q) use ($deckVersion) {
                $q->whereHas('match', fn ($mq) => $mq->where('deck_version_id', $deckVersion->id))
                    ->whereExists(function ($sub) {
                        $sub->select(DB::raw(1))
                            ->from('game_player as gp')
                            ->join('games as g', 'g.id', '=', 'gp.game_id')
                            ->whereRaw('g.match_id = match_archetypes.mtgo_match_id')
                            ->whereRaw('gp.player_id = match_archetypes.player_id')
                            ->where('gp.is_local', false);
                    });
            })
            ->orderBy('name')
            ->get()
            ->map(fn (Archetype $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'colorIdentity' => $a->color_identity,
            ]);
    }
}
