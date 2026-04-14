<?php

namespace App\Actions\Cards;

use App\Models\Archetype;
use App\Models\Deck;
use App\Models\DeckVersion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class GetCardGameStats
{
    public static function run(
        Deck $deck,
        ?DeckVersion $deckVersion = null,
        ?int $opponentArchetypeId = null,
        ?bool $onPlay = null,
        ?bool $isPostboard = null,
    ): Collection {
        $sideboardSource = $deckVersion ?? $deck->latestVersion;

        if (! $sideboardSource) {
            return collect();
        }

        $sideboardOracles = collect($sideboardSource->cards)
            ->filter(fn ($card) => $card['sideboard'] === 'true' || $card['sideboard'] === true)
            ->pluck('oracle_id')
            ->flip();

        $versionIds = $deckVersion
            ? [$deckVersion->id]
            : $deck->versions()->pluck('id')->all();

        if (empty($versionIds)) {
            return collect();
        }

        $query = DB::table('card_game_stats as cgs')
            ->join(DB::raw('(SELECT oracle_id, name, color_identity, type, image, local_image FROM cards WHERE oracle_id IS NOT NULL GROUP BY oracle_id) as c'), 'c.oracle_id', '=', 'cgs.oracle_id')
            ->whereIn('cgs.deck_version_id', $versionIds);

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
                c.local_image,
                COUNT(*) as total_games,
                SUM(cgs.quantity) as total_possible,
                SUM(cgs.kept) as total_kept,
                SUM(CASE WHEN cgs.kept > 0 AND cgs.won THEN 1 ELSE 0 END) as kept_won,
                SUM(CASE WHEN cgs.kept > 0 AND NOT cgs.won THEN 1 ELSE 0 END) as kept_lost,
                SUM(cgs.seen) as total_seen,
                SUM(CASE WHEN cgs.seen > 0 AND cgs.won THEN 1 ELSE 0 END) as seen_won,
                SUM(CASE WHEN cgs.seen > 0 AND NOT cgs.won THEN 1 ELSE 0 END) as seen_lost,
                SUM(cgs.cast) as total_cast,
                SUM(CASE WHEN cgs.cast > 0 AND cgs.won THEN 1 ELSE 0 END) as cast_won,
                SUM(CASE WHEN cgs.cast > 0 AND NOT cgs.won THEN 1 ELSE 0 END) as cast_lost,
                SUM(CASE WHEN cgs.is_postboard THEN 1 ELSE 0 END) as postboard_games,
                SUM(CASE WHEN cgs.sided_out THEN 1 ELSE 0 END) as sided_out_games,
                SUM(CASE WHEN cgs.sided_in THEN 1 ELSE 0 END) as sided_in_games,
                SUM(cgs.played) as total_played,
                SUM(cgs.kicked) as total_kicked,
                SUM(cgs.activated) as total_activated,
                SUM(cgs.flashback) as total_flashback,
                SUM(cgs.madness) as total_madness,
                SUM(cgs.evoked) as total_evoked
            ')
            ->orderBy('c.type')
            ->orderBy('c.name')
            ->get()
            ->map(fn ($row) => [
                'name' => $row->name,
                'oracleId' => $row->oracle_id,
                'colorIdentity' => $row->color_identity,
                'type' => $row->type,
                'image' => $row->local_image ? Storage::disk('cards')->url($row->local_image) : $row->image,
                'isSideboard' => $sideboardOracles->has($row->oracle_id),
                'totalGames' => (int) $row->total_games,
                'totalPossible' => (int) $row->total_possible,
                'totalKept' => (int) $row->total_kept,
                'keptWon' => (int) $row->kept_won,
                'keptLost' => (int) $row->kept_lost,
                'totalSeen' => (int) $row->total_seen,
                'seenWon' => (int) $row->seen_won,
                'seenLost' => (int) $row->seen_lost,
                'totalCast' => (int) $row->total_cast,
                'castWon' => (int) $row->cast_won,
                'castLost' => (int) $row->cast_lost,
                'postboardGames' => (int) $row->postboard_games,
                'sidedOutGames' => (int) $row->sided_out_games,
                'sidedInGames' => (int) $row->sided_in_games,
                'totalPlayed' => (int) $row->total_played,
                'totalKicked' => (int) $row->total_kicked,
                'totalActivated' => (int) $row->total_activated,
                'totalFlashback' => (int) $row->total_flashback,
                'totalMadness' => (int) $row->total_madness,
                'totalEvoked' => (int) $row->total_evoked,
            ]);
    }

    /**
     * Get archetypes that have card_game_stats data for this deck version.
     */
    public static function availableArchetypes(Deck $deck, ?DeckVersion $deckVersion = null): Collection
    {
        $versionIds = $deckVersion
            ? [$deckVersion->id]
            : $deck->versions()->pluck('id')->all();

        return Archetype::query()
            ->whereHas('matchArchetypes', function ($q) use ($versionIds) {
                $q->whereHas('match', fn ($mq) => $mq->whereIn('deck_version_id', $versionIds))
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
