<?php

use App\Enums\MatchOutcome;
use App\Enums\MatchState;
use App\Models\MtgoMatch;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Backfill outcome for existing completed matches based on game results.
     */
    public function up(): void
    {
        MtgoMatch::query()
            ->where('state', MatchState::Complete)
            ->whereNull('outcome')
            ->withCount([
                'games as games_won_count' => fn ($q) => $q->where('won', true),
                'games as games_lost_count' => fn ($q) => $q->where('won', false),
            ])
            ->each(function (MtgoMatch $match) {
                $match->updateQuietly([
                    'outcome' => MtgoMatch::determineOutcome(
                        $match->games_won_count,
                        $match->games_lost_count,
                    ),
                ]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        MtgoMatch::query()
            ->where('state', MatchState::Complete)
            ->whereNot('outcome', MatchOutcome::Unknown)
            ->update(['outcome' => null]);
    }
};
