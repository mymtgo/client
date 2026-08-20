<?php

namespace App\Updates;

use App\Jobs\RestoreLogDerivedCardStats;

class RestoreLogDerivedCardStatsFromShipQueue extends AppUpdate
{
    /**
     * Repair card stats zeroed by a recompute that had no usable game-log
     * source, rehydrating the counters from the ship queue's frozen payloads.
     *
     * Dispatched onto the same queue the regeneration jobs use rather than run
     * inline: RegenerateCardStatsWithCastingMethods only *queues* its
     * recomputes, so an inline restore would inspect stats those jobs have not
     * rewritten yet and find nothing to repair. FIFO puts this behind them.
     */
    public function run(): void
    {
        RestoreLogDerivedCardStats::dispatch()->onQueue('default');
    }
}
