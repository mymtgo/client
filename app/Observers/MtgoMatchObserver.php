<?php

namespace App\Observers;

use App\Models\Game;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use Illuminate\Support\Facades\DB;

class MtgoMatchObserver
{
    /**
     * Clean up all related records when a match is permanently deleted.
     */
    public function deleting(MtgoMatch $match): void
    {
        $gameIds = $match->games()->pluck('id');
        $gameMtgoIds = $match->games()->pluck('mtgo_id');

        // match_archetypes
        DB::table('match_archetypes')
            ->where('mtgo_match_id', $match->id)
            ->delete();

        // card_game_stats
        if ($gameIds->isNotEmpty()) {
            DB::table('card_game_stats')
                ->whereIn('game_id', $gameIds)
                ->delete();
        }

        // game_timelines
        if ($gameIds->isNotEmpty()) {
            DB::table('game_timelines')
                ->whereIn('game_id', $gameIds)
                ->delete();
        }

        // game_player
        if ($gameIds->isNotEmpty()) {
            DB::table('game_player')
                ->whereIn('game_id', $gameIds)
                ->delete();
        }

        // games
        Game::where('match_id', $match->id)->delete();

        // log events
        LogEvent::where(function ($q) use ($match, $gameMtgoIds) {
            $q->where('match_id', $match->mtgo_id)
                ->orWhere('match_token', $match->token);

            if ($gameMtgoIds->isNotEmpty()) {
                $q->orWhereIn('game_id', $gameMtgoIds);
            }
        })->delete();
    }
}
