<?php

namespace App\Actions\Cards;

use App\Models\Game;

class UpdateGameMetaFromLog
{
    /**
     * Persist turn count, dice roll, and mulligan counts for a game from parsed game-log data.
     *
     * Writes local_mulligans, opp_mulligans, local_dice, opp_dice onto the games row.
     * Local player is identified via match.account.username; opponent via match.opponent.username.
     *
     * @param  array<string, mixed>  $gameLogStats  output of ExtractCardsFromGameLog::run
     */
    public static function run(Game $game, array $gameLogStats, int $gameIndex): void
    {
        $meta = $gameLogStats['game_meta'][$gameIndex] ?? [];

        if (empty($meta)) {
            return;
        }

        if (! empty($meta['turn_count'])) {
            $game->update(['turn_count' => $meta['turn_count']]);
        }

        if (empty($meta['dice_rolls']) && empty($meta['mulligans'])) {
            return;
        }

        $match = $game->match()->with(['account', 'opponent'])->first();
        $localName = $match?->account?->username;
        $opponentName = $match?->opponent?->username;

        if (! $localName) {
            return;
        }

        $updates = [];

        if (isset($meta['mulligans'][$localName])) {
            $updates['local_mulligans'] = $meta['mulligans'][$localName];
        }

        if (isset($meta['mulligans'][$opponentName])) {
            $updates['opp_mulligans'] = $meta['mulligans'][$opponentName];
        }

        if (! empty($meta['dice_rolls'][$localName])) {
            $updates['local_dice'] = $meta['dice_rolls'][$localName];
        }

        if (! empty($meta['dice_rolls'][$opponentName])) {
            $updates['opp_dice'] = $meta['dice_rolls'][$opponentName];
        }

        if (! empty($updates)) {
            $game->update($updates);
        }
    }
}
