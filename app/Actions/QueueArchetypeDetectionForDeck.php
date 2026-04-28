<?php

namespace App\Actions;

use App\Jobs\DetermineMatchArchetypesJob;
use App\Models\Deck;
use App\Models\MtgoMatch;

class QueueArchetypeDetectionForDeck
{
    public function __invoke(Deck $deck, string $filterArchetype): int
    {
        $query = $deck->matches()
            ->where('matches.state', 'complete')
            ->filteredByArchetype($filterArchetype);

        $matchIds = (clone $query)->pluck('matches.id');

        if ($matchIds->isEmpty()) {
            return 0;
        }

        MtgoMatch::query()
            ->whereIn('id', $matchIds)
            ->update(['archetype_detection_queued_at' => now()]);

        $matchIds->each(fn (int $id) => DetermineMatchArchetypesJob::dispatch($id)->onQueue('match_archetypes'));

        return $matchIds->count();
    }
}
