<?php

namespace App\Actions\Matches;

use App\Models\Game;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PurgeMatch
{
    /**
     * Hard-delete a match and all related records, then reset
     * associated log events so the pipeline can rebuild from scratch.
     *
     * Returns the number of log events reset.
     */
    public static function run(MtgoMatch $match): int
    {
        return DB::transaction(function () use ($match) {
            $gameIds = $match->games()->pluck('id');
            $gameMtgoIds = $match->games()->pluck('mtgo_id');

            // 1. match_archetypes
            DB::table('match_archetypes')
                ->where('mtgo_match_id', $match->id)
                ->delete();

            // 2. game_timelines
            if ($gameIds->isNotEmpty()) {
                DB::table('game_timelines')
                    ->whereIn('game_id', $gameIds)
                    ->delete();
            }

            // 3. game_player
            if ($gameIds->isNotEmpty()) {
                DB::table('game_player')
                    ->whereIn('game_id', $gameIds)
                    ->delete();
            }

            // 4. games
            Game::where('match_id', $match->id)->delete();

            // 5. the match itself
            $mtgoId = $match->mtgo_id;
            $token = $match->token;
            $match->forceDelete();

            // 6. reset log events for reingestion
            $resetCount = LogEvent::where(function ($q) use ($mtgoId, $token, $gameMtgoIds) {
                $q->where('match_id', $mtgoId)
                    ->orWhere('match_token', $token);

                if ($gameMtgoIds->isNotEmpty()) {
                    $q->orWhereIn('game_id', $gameMtgoIds);
                }
            })->update(['processed_at' => null]);

            Log::channel('pipeline')->info("Match {$mtgoId}: purged — reset {$resetCount} log events for reingestion", [
                'token' => $token,
                'games_deleted' => $gameIds->count(),
            ]);

            return $resetCount;
        });
    }

    /**
     * Reset log events by match identifier without requiring a match record.
     * Used when events exist but no match was created.
     */
    public static function resetEventsByIdentifier(string $identifier): int
    {
        $resetCount = LogEvent::where(function ($q) use ($identifier) {
            $q->where('match_id', $identifier)
                ->orWhere('match_token', $identifier);
        })->update(['processed_at' => null]);

        if ($resetCount > 0) {
            Log::channel('pipeline')->info("Manual reset: {$resetCount} events reset for identifier {$identifier} (no match record found)");
        }

        return $resetCount;
    }
}
