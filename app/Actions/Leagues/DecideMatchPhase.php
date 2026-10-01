<?php

namespace App\Actions\Leagues;

use App\Actions\Overlay\DetectSideboarding;
use App\Actions\Sidecar\ReadSidecarStatus;
use App\Models\GameEvent;
use App\Models\MtgoMatch;
use App\Sidecar\SidecarPaths;
use App\Sidecar\SidecarTables;

class DecideMatchPhase
{
    /**
     * Sidecar event types that move a match between "playing" and
     * "sideboarding". The latest one, in fold order, decides the phase.
     *
     * @var list<string>
     */
    public const LIFECYCLE_TYPES = ['match_started', 'game_started', 'game_ended', 'sideboarding_started', 'match_ended'];

    /**
     * Whether the local player is sideboarding or playing in an active match.
     *
     * The sidecar reads MTGO's own sideboarding flag, so it wins when it is
     * live and has seen every game the log has. Otherwise (no helper, stale
     * heartbeat, or a helper that crashed mid-match and missed a game) the
     * log floor decides, via the same DetectSideboarding the game overlay
     * uses. Display only: nothing is persisted, so no authority flag gates it.
     *
     * @return 'sideboarding'|'in_game'
     */
    public static function run(MtgoMatch $match, int $loggedGameCount): string
    {
        $fromSidecar = self::sidecarPhase($match, $loggedGameCount);

        if ($fromSidecar !== null) {
            return $fromSidecar;
        }

        return DetectSideboarding::run($match) ? 'sideboarding' : 'in_game';
    }

    /**
     * Null when the sidecar cannot be trusted for this match. The directory
     * and table checks come first so a machine without the helper never
     * queries game_events (the zero-query invariant).
     *
     * @return 'sideboarding'|'in_game'|null
     */
    private static function sidecarPhase(MtgoMatch $match, int $loggedGameCount): ?string
    {
        if (! is_dir(SidecarPaths::directory()) || ! SidecarTables::ready()) {
            return null;
        }

        if (ReadSidecarStatus::run()?->isStale() !== false) {
            return null;
        }

        $events = GameEvent::query()
            ->where('match_mtgo_id', $match->mtgo_id)
            ->whereIn('type', self::LIFECYCLE_TYPES);

        $latest = (clone $events)
            ->orderByDesc('session_started_at')
            ->orderByDesc('seq')
            ->value('type');

        if ($latest === null) {
            return null;
        }

        // A helper that detached mid-match misses later games; the log has
        // moved on, so its last word cannot be trusted.
        if ((clone $events)->where('type', 'game_started')->count() < $loggedGameCount) {
            return null;
        }

        return $latest === 'sideboarding_started' ? 'sideboarding' : 'in_game';
    }
}
