<?php

namespace App\Actions\Matches;

use App\Models\Game;
use Carbon\Carbon;

/**
 * Apply per-game data extracted from a game log to a Game model.
 *
 * Single source of truth for projecting `winner`, `local_on_play`, and `ended_at`
 * from log entries onto the database. No-ops field-by-field when the source data
 * is missing — never clobbers existing values with defaults.
 */
class SyncGamePivots
{
    /**
     * Apply one game's parsed data to the Game model.
     *
     * @param  array{winner?: ?string, loser?: ?string, on_play?: ?string, ended_at?: ?string, started_at?: ?string}|null  $gameData
     */
    public static function forGame(Game $game, ?array $gameData, string $localUsername): void
    {
        if ($gameData === null) {
            return;
        }

        // The local player must actually be a participant of this game before we
        // project winner/on_play onto it. local_instance is set by CreateGames only
        // when the local player appears in the game state — mirrors the old
        // game_player presence check, and guards against a flaky resolved username.
        if ($game->local_instance === null) {
            return;
        }

        self::syncGameFields($game, $gameData, $localUsername);
        self::syncOnPlay($game, $gameData['on_play'] ?? null, $localUsername);
    }

    /**
     * Update the Game model's `won` and `ended_at` fields when the source has them.
     *
     * @param  array<string, mixed>  $gameData
     */
    private static function syncGameFields(Game $game, array $gameData, string $localUsername): void
    {
        $updates = [];
        $winner = $gameData['winner'] ?? null;

        if ($winner !== null) {
            $won = $winner === $localUsername;

            if ($game->won === null || (bool) $game->won !== $won) {
                $updates['won'] = $won;
            }
        }

        if ($game->ended_at === null && ! empty($gameData['ended_at'])) {
            $updates['ended_at'] = Carbon::parse($gameData['ended_at']);
        }

        if (! empty($updates)) {
            $game->update($updates);
        }
    }

    /**
     * Set games.local_on_play from the parsed "chooses to play" name.
     *
     * Skipped entirely when the log lacks a "chooses to play" line, leaving
     * the existing value intact.
     */
    private static function syncOnPlay(Game $game, ?string $onPlayName, string $localUsername): void
    {
        if ($onPlayName === null) {
            return;
        }

        $localOnPlay = $onPlayName === $localUsername;

        if ($game->local_on_play === null || (bool) $game->local_on_play !== $localOnPlay) {
            $game->update(['local_on_play' => $localOnPlay]);
        }
    }
}
