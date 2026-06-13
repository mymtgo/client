<?php

namespace App\Actions\Matches;

use App\Events\GameResultRecorded;
use App\Models\Game;
use Carbon\Carbon;

/**
 * Apply per-game data extracted from a game log to a Game model and its
 * game_player pivot rows.
 *
 * Single source of truth for projecting `winner`, `on_play`, `ended_at`
 * from log entries onto the database. No-ops field-by-field when the
 * source data is missing — never clobbers existing values with defaults.
 */
class SyncGamePivots
{
    /**
     * Apply one game's parsed data to the Game model + pivots.
     *
     * @param  array{winner?: ?string, loser?: ?string, on_play?: ?string, ended_at?: ?string, started_at?: ?string}|null  $gameData
     */
    public static function forGame(Game $game, ?array $gameData, string $localUsername): void
    {
        if ($gameData === null) {
            return;
        }

        $game->loadMissing('players');

        if (! $game->players->contains(fn ($p) => $p->username === $localUsername)) {
            return;
        }

        self::syncGameFields($game, $gameData, $localUsername);
        self::syncOnPlayPivot($game, $gameData['on_play'] ?? null, $localUsername);
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

            if (array_key_exists('won', $updates)) {
                GameResultRecorded::dispatch($game->match_id);
            }
        }
    }

    /**
     * Update on_play for both players' pivot rows from the parsed name.
     *
     * Skipped entirely when the log lacks a "chooses to play" line, leaving
     * any pre-existing pivot value intact.
     */
    private static function syncOnPlayPivot(Game $game, ?string $onPlayName, string $localUsername): void
    {
        if ($onPlayName === null) {
            return;
        }

        $local = $game->players->first(fn ($p) => $p->username === $localUsername);
        $opponent = $game->players->first(fn ($p) => $p->username !== $localUsername);

        if (! $local || ! $opponent) {
            return;
        }

        self::writeOnPlay($game, $local->id, $onPlayName === $local->username, (bool) $local->pivot->on_play);
        self::writeOnPlay($game, $opponent->id, $onPlayName === $opponent->username, (bool) $opponent->pivot->on_play);
    }

    private static function writeOnPlay(Game $game, int $playerId, bool $value, bool $current): void
    {
        if ($current === $value) {
            return;
        }

        $game->players()->updateExistingPivot($playerId, ['on_play' => $value]);
    }
}
