<?php

namespace App\Actions\Matches;

use App\Models\Game;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use Illuminate\Support\Facades\DB;

class PurgeMatchDerivedData
{
    /**
     * Delete all records derived from a match: archetype links, card stats,
     * timelines, game pivots and games. Log events are only deleted when
     * $includeLogEvents is true — reprocessing keeps them as the source of
     * truth to rebuild the match from.
     */
    public static function run(MtgoMatch $match, bool $includeLogEvents = true): void
    {
        $gameIds = $match->games()->pluck('id');
        $gameMtgoIds = $match->games()->pluck('mtgo_id');

        DB::table('match_archetypes')
            ->where('mtgo_match_id', $match->id)
            ->delete();

        if ($gameIds->isNotEmpty()) {
            DB::table('card_game_stats')
                ->whereIn('game_id', $gameIds)
                ->delete();

            DB::table('game_timelines')
                ->whereIn('game_id', $gameIds)
                ->delete();

            DB::table('game_player')
                ->whereIn('game_id', $gameIds)
                ->delete();
        }

        Game::where('match_id', $match->id)->delete();

        if ($includeLogEvents) {
            LogEvent::where(function ($q) use ($match, $gameMtgoIds) {
                $q->where('match_id', $match->mtgo_id)
                    ->orWhere('match_token', $match->token);

                if ($gameMtgoIds->isNotEmpty()) {
                    $q->orWhereIn('game_id', $gameMtgoIds);
                }
            })->delete();
        }
    }
}
