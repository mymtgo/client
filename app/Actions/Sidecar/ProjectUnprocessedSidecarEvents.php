<?php

namespace App\Actions\Sidecar;

use App\Models\GameEvent;
use App\Models\MtgoMatch;
use App\Sidecar\SidecarPaths;
use App\Sidecar\SidecarTables;

/**
 * Sweeps sidecar events that no log activity will ever bring back into
 * projection.
 *
 * `ApplySidecarProjection` normally runs from inside `ProcessMatchEvents`,
 * which only visits matches with unprocessed `log_events`. A `game_ended`,
 * a final `clock_tick` or a `match_ended` that the sidecar flushes after
 * the log's last line for that match would otherwise sit with
 * `processed_at IS NULL` forever, and the clock end, the result and the
 * boundary diffs would never be written. `known_mtgo_lies_and_traps.md`
 * says to assume no clean match end, so the self-healing later log line
 * cannot be relied on.
 *
 * Events whose match has no `MtgoMatch` row yet keep `processed_at` NULL
 * and are retried on the next tick, which is how a sidecar that saw the
 * match before the log did catches up.
 */
class ProjectUnprocessedSidecarEvents
{
    /** @return int matches projected this tick */
    public static function run(): int
    {
        // Directory check first, mirroring IngestSidecarEvents: a machine with
        // no sidecar at all (macOS, dev) must still add zero queries per tick.
        if (! is_dir(SidecarPaths::directory())) {
            return 0;
        }

        if (! SidecarTables::ready()) {
            return 0;
        }

        $matchMtgoIds = GameEvent::query()
            ->whereNull('processed_at')
            ->whereNotNull('match_mtgo_id')
            ->distinct()
            ->pluck('match_mtgo_id');

        if ($matchMtgoIds->isEmpty()) {
            return 0;
        }

        $matches = MtgoMatch::query()
            ->whereIn('mtgo_id', $matchMtgoIds)
            ->get()
            ->keyBy(fn (MtgoMatch $match) => (string) $match->mtgo_id);

        $projected = 0;

        foreach ($matchMtgoIds as $matchMtgoId) {
            $match = $matches->get((string) $matchMtgoId);

            if ($match === null) {
                continue;
            }

            ApplySidecarProjection::run($match);

            // The projection marks its own rows, but it returns early when
            // the fold finds nothing it consumes. Marking here as well keeps
            // a match whose only unprocessed events are replay detail
            // (card_tapped, mana_pool_changed) from being re-swept forever.
            GameEvent::query()
                ->where('match_mtgo_id', $matchMtgoId)
                ->whereNull('processed_at')
                ->update(['processed_at' => now()]);

            $projected++;
        }

        return $projected;
    }
}
