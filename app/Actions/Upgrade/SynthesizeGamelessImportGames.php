<?php

namespace App\Actions\Upgrade;

use App\Models\Game;
use App\Models\MtgoMatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SynthesizeGamelessImportGames
{
    /**
     * One-shot, idempotent cleanup run across all matches.
     *
     * Two responsibilities:
     *  1. Synthesize game rows for imported matches that have games_won/games_lost
     *     recorded but zero rows in the games table.
     *  2. Delete orphan rows left by previously missing cascade deletes:
     *     - games whose match_id references no existing matches row.
     *     - match_archetypes whose mtgo_match_id references no existing matches row.
     */
    public static function run(): void
    {
        self::synthesizeGamelessMatches();
        self::cleanOrphanGames();
        self::cleanOrphanMatchArchetypes();
    }

    /**
     * For every match that carries a non-zero games_won or games_lost total but
     * has no game rows yet, insert synthetic game rows derived from those counts.
     *
     * Idempotent: matches that already have at least one game are skipped, so a
     * re-run leaves them untouched.
     */
    private static function synthesizeGamelessMatches(): void
    {
        MtgoMatch::query()
            ->where(function ($q) {
                $q->where('games_won', '>', 0)
                    ->orWhere('games_lost', '>', 0);
            })
            ->whereDoesntHave('games')
            ->chunkById(200, function ($matches) {
                foreach ($matches as $match) {
                    self::synthesizeForMatch($match);
                }
            });
    }

    /**
     * Insert synthetic game rows for a single gameless match.
     *
     * Sentinel strategy for mtgo_id:
     *  - 0 is used as the synthetic sentinel. MTGO never issues an ID of 0 for
     *    a real game, so this safely marks rows as artificially created.
     *
     * NOT NULL columns that must be populated (from the original create_games_table
     * migration): match_id, mtgo_id, started_at. (ended_at and won are nullable
     * since the make_games_ended_at_and_won_nullable migration.)
     */
    private static function synthesizeForMatch(MtgoMatch $match): void
    {
        $now = now()->toDateTimeString();
        $startedAt = $match->started_at?->toDateTimeString() ?? $now;

        $rows = [];

        for ($i = 0; $i < $match->games_won; $i++) {
            $rows[] = [
                'match_id' => $match->id,
                'mtgo_id' => '0',
                'won' => true,
                'started_at' => $startedAt,
                'ended_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        for ($i = 0; $i < $match->games_lost; $i++) {
            $rows[] = [
                'match_id' => $match->id,
                'mtgo_id' => '0',
                'won' => false,
                'started_at' => $startedAt,
                'ended_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if (! empty($rows)) {
            DB::table('games')->insert($rows);
        }
    }

    /**
     * Delete games rows whose match_id has no corresponding matches row.
     *
     * Orphan games left by legacy missing-cascade deletes still carry child rows.
     * game_decks, card_game_stats and card_stat_ship_queue cascade on delete, but
     * game_player and game_timelines use RESTRICT foreign keys, so those children
     * must be removed explicitly first or SQLite rejects the delete with a
     * "FOREIGN KEY constraint failed" error.
     */
    private static function cleanOrphanGames(): void
    {
        $orphanGameIds = DB::table('games')
            ->whereNotIn('match_id', DB::table('matches')->select('id'))
            ->pluck('id');

        if ($orphanGameIds->isEmpty()) {
            return;
        }

        if (Schema::hasTable('game_player')) {
            DB::table('game_player')->whereIn('game_id', $orphanGameIds)->delete();
        }

        DB::table('game_timelines')->whereIn('game_id', $orphanGameIds)->delete();

        DB::table('games')->whereIn('id', $orphanGameIds)->delete();
    }

    /**
     * Delete match_archetypes rows whose mtgo_match_id has no corresponding
     * matches row.
     */
    private static function cleanOrphanMatchArchetypes(): void
    {
        DB::table('match_archetypes')
            ->whereNotIn('mtgo_match_id', DB::table('matches')->select('id'))
            ->delete();
    }
}
