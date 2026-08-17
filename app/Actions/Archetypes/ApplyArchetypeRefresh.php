<?php

namespace App\Actions\Archetypes;

use App\Jobs\DetermineMatchArchetypesJob;
use App\Jobs\DownloadArchetypes;
use App\Jobs\RefreshArchetypes;
use App\Models\Archetype;
use App\Models\Deck;
use App\Models\MatchArchetype;
use App\Models\MtgoMatch;
use Illuminate\Database\Query\Builder;

class ApplyArchetypeRefresh
{
    /**
     * Sync archetypes with the API: upsert additions/renames, delete archetypes the
     * API no longer knows (manual and fallback are never touched), and handle every
     * match that pointed at a deleted archetype. A mapping (removed id => successor
     * id) marks a deletion as a rename: its matches, deck links, and merge children
     * move to the successor directly, with no re-detection. Unmapped deletions queue
     * re-detection instead. Deletion relies on the schema's FK behaviour:
     * match_archetypes cascade, while decks.archetype_id and
     * archetypes.merged_into_id null out.
     *
     * @param  array<int, int>  $mappings
     * @return array{added: int, updated: int, removed: int, remapped: int, matches_queued: int}
     */
    public static function run(array $mappings = []): array
    {
        $plan = ComputeArchetypeRefreshPlan::run();

        DownloadArchetypes::upsert($plan['api']);

        $removedIds = array_column($plan['removed'], 'id');
        $validMappings = self::validMappings($mappings, $removedIds);

        foreach ($validMappings as $removedId => $successorId) {
            self::remap($removedId, $successorId);
        }

        $unmappedIds = array_values(array_diff($removedIds, array_keys($validMappings)));

        $matchIds = $unmappedIds === [] ? [] : MatchArchetype::query()
            ->whereIn('archetype_id', $unmappedIds)
            ->distinct()
            ->pluck('mtgo_match_id')
            ->all();

        if ($removedIds !== []) {
            Archetype::query()->whereIn('id', $removedIds)->delete();
        }

        if ($matchIds !== []) {
            MtgoMatch::query()
                ->whereIn('id', $matchIds)
                ->update(['archetype_detection_queued_at' => now()]);

            foreach ($matchIds as $matchId) {
                DetermineMatchArchetypesJob::dispatch($matchId)->onQueue('match_archetypes');
            }
        }

        RefreshArchetypes::dispatch();

        return [
            'added' => $plan['added'],
            'updated' => $plan['updated'],
            'removed' => count($removedIds),
            'remapped' => count($validMappings),
            'matches_queued' => count($matchIds),
        ];
    }

    /**
     * A mapping is only usable when the source really is being removed and the
     * successor is a real archetype that survives the refresh.
     *
     * @param  array<int|string, int|string|null>  $mappings
     * @param  array<int, int>  $removedIds
     * @return array<int, int>
     */
    private static function validMappings(array $mappings, array $removedIds): array
    {
        $removedIdSet = array_flip($removedIds);

        $valid = [];

        foreach ($mappings as $removedId => $successorId) {
            $removedId = (int) $removedId;
            $successorId = (int) $successorId;

            if ($successorId === 0
                || ! isset($removedIdSet[$removedId])
                || isset($removedIdSet[$successorId])
                || ! Archetype::query()->whereKey($successorId)->exists()
            ) {
                continue;
            }

            $valid[$removedId] = $successorId;
        }

        return $valid;
    }

    /**
     * Move everything pointing at a removed archetype onto its successor. Match
     * rows that would duplicate an existing successor assignment are left in
     * place and die with the cascade delete. The successor's own decklist
     * variants differ from the removed archetype's, so archetype_deck_id is
     * cleared rather than carried across.
     */
    private static function remap(int $removedId, int $successorId): void
    {
        MatchArchetype::query()
            ->where('archetype_id', $removedId)
            ->whereNotExists(function (Builder $query) use ($successorId) {
                $query->from('match_archetypes as existing')
                    ->whereColumn('existing.mtgo_match_id', 'match_archetypes.mtgo_match_id')
                    ->whereColumn('existing.player_id', 'match_archetypes.player_id')
                    ->where('existing.archetype_id', $successorId);
            })
            ->update(['archetype_id' => $successorId, 'archetype_deck_id' => null]);

        Deck::withTrashed()
            ->where('archetype_id', $removedId)
            ->update(['archetype_id' => $successorId]);

        Archetype::query()
            ->where('merged_into_id', $removedId)
            ->update(['merged_into_id' => $successorId]);
    }
}
