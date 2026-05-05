<?php

namespace App\Updates;

use App\Jobs\BackfillTournaments;

/**
 * One-shot dispatch of the tournament backfill job after upgrading to the
 * Tournaments feature. Existing matches already carry tournament_event_id
 * but no tournament_id link; the job walks them and creates rows from API
 * metadata.
 */
class DispatchTournamentBackfill extends AppUpdate
{
    public function run(): void
    {
        BackfillTournaments::dispatch();
    }
}
