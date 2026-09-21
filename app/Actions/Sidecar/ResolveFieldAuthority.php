<?php

namespace App\Actions\Sidecar;

use App\Sidecar\Resolution;

class ResolveFieldAuthority
{
    /**
     * Pure chooser. The sidecar wins only when every gate is open; otherwise
     * the log value wins. A null on one side never counts as disagreement.
     *
     * A null log value is not a licence to use the sidecar's. Almost every
     * field starts out null on the log side (`games.won` while the game is
     * in progress, `game_player.on_play` until SyncGamePivots learns it), so
     * treating null as "no opinion" would let an ungated, unverified sidecar
     * write the field on the first tick and make a flag-off install behave
     * differently from today's. The gates decide in both directions; with
     * the flag off the sidecar never touches the field.
     */
    public static function run(
        string $field,
        mixed $logValue,
        mixed $sidecarValue,
        bool $flagOn,
        bool $coverageComplete,
        bool $allVerified,
        bool $crossCheckPassed,
    ): Resolution {
        $sidecarWins = $flagOn && $coverageComplete && $allVerified && $crossCheckPassed;

        if ($sidecarValue === null) {
            return new Resolution($logValue, 'log', false);
        }

        if ($logValue === null) {
            return $sidecarWins
                ? new Resolution($sidecarValue, 'sidecar', false)
                : new Resolution(null, 'log', false);
        }

        $disagree = self::normalise($logValue) !== self::normalise($sidecarValue);

        return $sidecarWins
            ? new Resolution($sidecarValue, 'sidecar', $disagree)
            : new Resolution($logValue, 'log', $disagree);
    }

    private static function normalise(mixed $value): string
    {
        if (is_array($value)) {
            ksort($value);
            $value = array_map(fn ($v) => is_array($v) ? json_decode(self::normalise($v), true) : $v, $value);
        }

        return json_encode($value, JSON_THROW_ON_ERROR);
    }
}
