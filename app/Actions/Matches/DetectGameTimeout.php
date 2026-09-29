<?php

namespace App\Actions\Matches;

use App\Models\Game;

class DetectGameTimeout
{
    /**
     * A clock at or under this at game end counts as run out. The sidecar
     * writes a final clock reading when a game ends, so a real timeout
     * should read about zero. Provisional until a real timeout has been
     * observed (see the report payload spec).
     */
    public const THRESHOLD_MS = 1000;

    /**
     * Whether one side of a game lost it on time. MTGO reports no timeout
     * reason, so this is inferred from the clock. Null when that side's
     * clock or the game result is unknown, so a machine without the
     * sidecar never reports a false "did not time out".
     */
    public static function run(Game $game, bool $local): ?bool
    {
        $clock = $game->players->first(fn ($player) => (bool) $player->pivot->is_local === $local)?->pivot->clock_remaining_ms_end;

        if ($clock === null || $game->won === null) {
            return null;
        }

        $lost = $local ? ! $game->won : $game->won;

        return $lost && $clock <= self::THRESHOLD_MS;
    }
}
