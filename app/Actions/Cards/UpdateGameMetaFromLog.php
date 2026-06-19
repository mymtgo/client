<?php

namespace App\Actions\Cards;

use App\Models\Game;
use Illuminate\Support\Facades\DB;

class UpdateGameMetaFromLog
{
    /**
     * Persist turn count, dice roll, and mulligan counts for a game from parsed game-log data.
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

        $localName = $game->players->first(fn ($p) => $p->pivot->is_local)?->username;
        $opponentName = $game->players->first(fn ($p) => ! $p->pivot->is_local)?->username;

        if ($localName && (! empty($meta['dice_rolls']) || ! empty($meta['mulligans']))) {
            DB::table('game_player')
                ->where('game_id', $game->id)
                ->where('is_local', true)
                ->update([
                    'dice_roll' => $meta['dice_rolls'][$localName] ?? null,
                    'mulligan_count' => $meta['mulligans'][$localName] ?? 0,
                ]);

            DB::table('game_player')
                ->where('game_id', $game->id)
                ->where('is_local', false)
                ->update([
                    'dice_roll' => $meta['dice_rolls'][$opponentName] ?? null,
                    'mulligan_count' => $meta['mulligans'][$opponentName] ?? 0,
                ]);
        }
    }
}
