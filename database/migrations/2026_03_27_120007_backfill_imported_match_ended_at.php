<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Backfill ended_at for imported matches and their games using game log timestamps.
     *
     * Early imports lost ended_at due to a validation stripping bug.
     * Game logs have reliable timestamps on every entry, so we can
     * recover ended_at from the last decoded entry per game.
     */
    public function up(): void
    {
        // Backfill match ended_at from the latest game ended_at
        DB::table('matches')
            ->where('imported', true)
            ->whereNull('ended_at')
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('games')
                    ->whereColumn('games.match_id', 'matches.id')
                    ->whereNotNull('games.ended_at');
            })
            ->update([
                'ended_at' => DB::raw('(
                    SELECT MAX(g.ended_at)
                    FROM games g
                    WHERE g.match_id = matches.id
                    AND g.ended_at IS NOT NULL
                )'),
            ]);

        // Backfill game ended_at from game log decoded entries
        $matches = DB::table('matches')
            ->where('imported', true)
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('games')
                    ->whereColumn('games.match_id', 'matches.id')
                    ->whereNull('games.ended_at');
            })
            ->pluck('token', 'id');

        foreach ($matches as $matchId => $token) {
            $gameLog = DB::table('game_logs')
                ->where('match_token', $token)
                ->first();

            if (! $gameLog || ! $gameLog->decoded_entries) {
                continue;
            }

            $entries = json_decode($gameLog->decoded_entries, true);

            if (empty($entries)) {
                continue;
            }

            // Use last entry timestamp as match ended_at
            $lastTimestamp = collect($entries)->pluck('timestamp')->filter()->last();

            if ($lastTimestamp) {
                DB::table('matches')
                    ->where('id', $matchId)
                    ->whereNull('ended_at')
                    ->update(['ended_at' => $lastTimestamp]);
            }

            // Backfill game ended_at from game started_at ordering
            // Games are created in order, so we can use the next game's started_at
            // minus a second, or the match ended_at for the last game
            $games = DB::table('games')
                ->where('match_id', $matchId)
                ->orderBy('started_at')
                ->get();

            foreach ($games as $i => $game) {
                if ($game->ended_at !== null) {
                    continue;
                }

                $nextGame = $games[$i + 1] ?? null;
                $endedAt = $nextGame ? $nextGame->started_at : $lastTimestamp;

                if ($endedAt) {
                    DB::table('games')
                        ->where('id', $game->id)
                        ->update(['ended_at' => $endedAt]);
                }
            }
        }
    }

    public function down(): void
    {
        // Not reversible — the data was always supposed to be there
    }
};
