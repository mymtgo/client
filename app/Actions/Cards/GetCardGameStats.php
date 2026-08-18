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
        bool $opponent = false,
    ): Collection {
        $sideboardSource = $deckVersion ?? $deck->latestVersion;

        if (! $sideboardSource) {
            return collect();
        }

        $versionIds = $deckVersion
            ? [$deckVersion->id]
            : $deck->versions()->pluck('id')->all();

        if (empty($versionIds)) {
            return collect();
        }

        $sideboardOracles = self::resolveSideboardOraclesForVersion($sideboardSource, $opponent);

        return self::forVersionIds($versionIds, $sideboardOracles, $opponentArchetypeId, $onPlay, $isPostboard, $opponent);
    }

    /**
     * @param  array<int, int>  $deckVersionIds
     * @param  Collection<string, true>  $sideboardOracleIds  oracle_id => true map; pass empty collection to skip sideboard flagging
     */
    public static function forVersionIds(
        array $deckVersionIds,
        Collection $sideboardOracleIds,
        ?int $opponentArchetypeId = null,
        ?bool $onPlay = null,
        ?bool $isPostboard = null,
        bool $opponent = false,
    ): Collection {
        if (empty($deckVersionIds)) {
            return collect();
        }

        $applySharedFilters = function ($q) use ($deckVersionIds, $isPostboard, $opponentArchetypeId, $onPlay): void {
            $q->whereIn('cgs.deck_version_id', $deckVersionIds);
            $q->when($isPostboard !== null, fn ($qq) => $qq->where('cgs.is_postboard', $isPostboard));

            if ($opponentArchetypeId) {
                ApplyOpponentArchetypeFilter::to($q, $opponentArchetypeId);
            }

            if ($onPlay !== null) {
                if (! $opponentArchetypeId) {
                    $q->join('games as g', 'g.id', '=', 'cgs.game_id');
                }

                $q->whereExists(function ($sub) use ($onPlay) {
                    $sub->select(DB::raw(1))
                        ->from('game_player as local_gp')
                        ->whereRaw('local_gp.game_id = g.id')
                        ->where('local_gp.is_local', true)
                        ->where('local_gp.on_play', $onPlay);
                });
            }
        };

        $query = DB::table('card_game_stats as cgs')
            ->join(DB::raw('(SELECT oracle_id, name, color_identity, type, image, local_image FROM cards WHERE oracle_id IS NOT NULL GROUP BY oracle_id) as c'), 'c.oracle_id', '=', 'cgs.oracle_id')
            ->where('cgs.opponent', $opponent);
        $applySharedFilters($query);

        // Opponent rows are emitted only when a signal fires, so COUNT(*) on the
        // grouped query gives signal-games not total-games. Compute total games
        // played from local rows (one per card per game) and use that as denominator.
        $totalOpponentGames = null;
        if ($opponent) {
            $denomQuery = DB::table('card_game_stats as cgs')->where('cgs.opponent', false);
            $applySharedFilters($denomQuery);
            $totalOpponentGames = (int) $denomQuery->distinct()->count('cgs.game_id');
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
                SUM(CASE WHEN cgs.kept > 0 THEN 1 ELSE 0 END) as kept_games,
                SUM(CASE WHEN cgs.kept > 0 AND cgs.won THEN 1 ELSE 0 END) as kept_won,
                SUM(CASE WHEN cgs.kept > 0 AND NOT cgs.won THEN 1 ELSE 0 END) as kept_lost,
                SUM(cgs.seen) as total_seen,
                SUM(CASE WHEN cgs.seen > 0 THEN 1 ELSE 0 END) as seen_games,
                SUM(CASE WHEN cgs.seen > 0 AND cgs.won THEN 1 ELSE 0 END) as seen_won,
                SUM(CASE WHEN cgs.seen > 0 AND NOT cgs.won THEN 1 ELSE 0 END) as seen_lost,
                SUM(cgs.cast) as total_cast,
                SUM(CASE WHEN cgs.cast > 0 THEN 1 ELSE 0 END) as cast_games,
                SUM(CASE WHEN cgs.cast > 0 AND cgs.won THEN 1 ELSE 0 END) as cast_won,
                SUM(CASE WHEN cgs.cast > 0 AND NOT cgs.won THEN 1 ELSE 0 END) as cast_lost,
                SUM(CASE WHEN cgs.is_postboard THEN 1 ELSE 0 END) as postboard_games,
                SUM(CASE WHEN cgs.sided_out THEN 1 ELSE 0 END) as sided_out_games,
                SUM(CASE WHEN cgs.sided_in THEN 1 ELSE 0 END) as sided_in_games,
                SUM(cgs.played) as total_played,
                SUM(CASE WHEN cgs.played > 0 THEN 1 ELSE 0 END) as played_games,
                SUM(cgs.kicked) as total_kicked,
                SUM(cgs.activated) as total_activated,
                SUM(cgs.flashback) as total_flashback,
                SUM(cgs.madness) as total_madness,
                SUM(cgs.evoked) as total_evoked,
                SUM(cgs.warp) as total_warp,
                SUM(cgs.free_cast) as total_free_cast,
                SUM(cgs.bargained) as total_bargained,
                SUM(cgs.dashed) as total_dashed,
                SUM(cgs.bestowed) as total_bestowed,
                SUM(cgs.replicated) as total_replicated,
                SUM(cgs.spectacle) as total_spectacle,
                SUM(cgs.rebound) as total_rebound,
                SUM(cgs.escaped) as total_escaped,
                SUM(cgs.ninjutsu) as total_ninjutsu,
                SUM(cgs.suspended) as total_suspended,
                SUM(cgs.buyback) as total_buyback,
                SUM(cgs.disturb) as total_disturb,
                SUM(cgs.foretold) as total_foretold,
                SUM(cgs.retraced) as total_retraced,
                SUM(cgs.mayhem) as total_mayhem,
                SUM(cgs.miracle) as total_miracle,
                SUM(cgs.gifted) as total_gifted,
                SUM(cgs.casualty) as total_casualty,
                SUM(CASE WHEN cgs.pregame_revealed THEN 1 ELSE 0 END) as pregame_revealed_games,
                SUM(CASE WHEN cgs.pregame_played THEN 1 ELSE 0 END) as pregame_played_games,
                SUM(CASE WHEN cgs.pregame_revealed OR cgs.pregame_played THEN 1 ELSE 0 END) as pregame_games,
                SUM(CASE WHEN (cgs.pregame_revealed OR cgs.pregame_played) AND cgs.won THEN 1 ELSE 0 END) as pregame_won,
                SUM(CASE WHEN (cgs.pregame_revealed OR cgs.pregame_played) AND NOT cgs.won THEN 1 ELSE 0 END) as pregame_lost
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
                'isSideboard' => $sideboardOracleIds->has($row->oracle_id),
                'totalGames' => $totalOpponentGames ?? (int) $row->total_games,
                'totalPossible' => (int) $row->total_possible,
                'totalKept' => (int) $row->total_kept,
                'keptGames' => (int) $row->kept_games,
                'keptWon' => (int) $row->kept_won,
                'keptLost' => (int) $row->kept_lost,
                'totalSeen' => (int) $row->total_seen,
                'seenGames' => (int) $row->seen_games,
                'seenWon' => (int) $row->seen_won,
                'seenLost' => (int) $row->seen_lost,
                'totalCast' => (int) $row->total_cast,
                'castGames' => (int) $row->cast_games,
                'castWon' => (int) $row->cast_won,
                'castLost' => (int) $row->cast_lost,
                'postboardGames' => (int) $row->postboard_games,
                'sidedOutGames' => (int) $row->sided_out_games,
                'sidedInGames' => (int) $row->sided_in_games,
                'totalPlayed' => (int) $row->total_played,
                'playedGames' => (int) $row->played_games,
                'totalKicked' => (int) $row->total_kicked,
                'totalActivated' => (int) $row->total_activated,
                'totalFlashback' => (int) $row->total_flashback,
                'totalMadness' => (int) $row->total_madness,
                'totalEvoked' => (int) $row->total_evoked,
                'totalWarp' => (int) $row->total_warp,
                'totalFreeCast' => (int) $row->total_free_cast,
                'totalBargained' => (int) $row->total_bargained,
                'totalDashed' => (int) $row->total_dashed,
                'totalBestowed' => (int) $row->total_bestowed,
                'totalReplicated' => (int) $row->total_replicated,
                'totalSpectacle' => (int) $row->total_spectacle,
                'totalRebound' => (int) $row->total_rebound,
                'totalEscaped' => (int) $row->total_escaped,
                'totalNinjutsu' => (int) $row->total_ninjutsu,
                'totalSuspended' => (int) $row->total_suspended,
                'totalBuyback' => (int) $row->total_buyback,
                'totalDisturb' => (int) $row->total_disturb,
                'totalForetold' => (int) $row->total_foretold,
                'totalRetraced' => (int) $row->total_retraced,
                'totalMayhem' => (int) $row->total_mayhem,
                'totalMiracle' => (int) $row->total_miracle,
                'totalGifted' => (int) $row->total_gifted,
                'totalCasualty' => (int) $row->total_casualty,
                'pregameRevealedGames' => (int) $row->pregame_revealed_games,
                'pregamePlayedGames' => (int) $row->pregame_played_games,
                'pregameGames' => (int) $row->pregame_games,
                'pregameWon' => (int) $row->pregame_won,
                'pregameLost' => (int) $row->pregame_lost,
            ]);
    }

    /**
     * Get archetypes that have card_game_stats data for the given deck versions.
     */
    public static function availableArchetypes(Deck $deck, ?DeckVersion $deckVersion = null): Collection
    {
        $versionIds = $deckVersion
            ? [$deckVersion->id]
            : $deck->versions()->pluck('id')->all();

        return self::availableArchetypesForVersionIds($versionIds);
    }

    /**
     * @param  array<int, int>  $deckVersionIds
     */
    public static function availableArchetypesForVersionIds(array $deckVersionIds): Collection
    {
        if (empty($deckVersionIds)) {
            return collect();
        }

        return Archetype::query()
            ->whereHas('matchArchetypes', function ($q) use ($deckVersionIds) {
                $q->whereHas('match', fn ($mq) => $mq->whereIn('deck_version_id', $deckVersionIds))
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

    /**
     * @return Collection<string, true>
     */
    public static function resolveSideboardOraclesForVersion(DeckVersion $version, bool $opponent): Collection
    {
        if ($opponent) {
            return collect();
        }

        return collect($version->cards)
            ->filter(fn ($card) => ($card['sideboard'] ?? false) === 'true' || ($card['sideboard'] ?? false) === true)
            ->pluck('oracle_id')
            ->filter(fn ($id) => $id !== null)
            ->flip();
    }
}
