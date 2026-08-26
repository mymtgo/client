<?php

use App\Enums\LeagueState;
use App\Enums\MatchState;
use App\Models\League;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Close league runs left Active by the old hardcoded five-match
     * completion threshold. A draft league is three rounds, so every
     * finished draft run stayed Active forever and rendered a live badge.
     *
     * Deliberately does not call CompleteLeague: a migration records a
     * one-off transition and must keep behaving the same when that action
     * changes. completed_at is the last match's end, not now().
     */
    public function up(): void
    {
        League::query()
            ->where('state', LeagueState::Active)
            ->get()
            ->each(function (League $league): void {
                $played = $league->matches()->where('state', MatchState::Complete);

                if ($played->count() < $league->kind->roundCount()) {
                    return;
                }

                $league->update([
                    'state' => LeagueState::Complete,
                    'completed_at' => $played->max('ended_at') ?? now(),
                ]);
            });
    }

    /**
     * Irreversible: the pre-migration state of each league is not recorded,
     * and reverting would reopen runs that are genuinely finished.
     */
    public function down(): void
    {
        //
    }
};
