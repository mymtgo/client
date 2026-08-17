<?php

namespace App\Actions\Archetypes;

use App\Models\Archetype;
use App\Models\MatchArchetype;
use Illuminate\Support\Facades\Http;

class ComputeArchetypeRefreshPlan
{
    /**
     * Diff the API archetype list against local non-manual, non-fallback archetypes.
     *
     * @return array{
     *     api: array<int, array{uuid: string, name: string, format: string, colorIdentity: string|null}>,
     *     added: int,
     *     updated: int,
     *     removed: array<int, array{id: int, name: string, format: string|null, match_count: int, suggested_id: int|null}>,
     *     match_ids: array<int, int>,
     * }
     */
    public static function run(): array
    {
        /** @var array<int, array{uuid: string, name: string, format: string, colorIdentity: string|null}> $apiRows */
        $apiRows = Http::mymtgoApi()->throw()->get('/api/archetypes')->json();

        $apiByUuid = collect($apiRows)->keyBy('uuid');

        $local = Archetype::query()
            ->where('manual', false)
            ->where('is_fallback', false)
            ->withCount('matchArchetypes')
            ->get();

        $removed = $local
            ->reject(fn (Archetype $archetype) => $apiByUuid->has($archetype->uuid))
            ->values();

        $updated = $local->filter(function (Archetype $archetype) use ($apiByUuid) {
            $row = $apiByUuid->get($archetype->uuid);

            if ($row === null) {
                return false;
            }

            return $archetype->name !== $row['name']
                || $archetype->format !== strtolower($row['format'])
                || $archetype->color_identity !== ($row['colorIdentity'] ?? null);
        });

        $localUuids = $local->pluck('uuid');
        $added = $apiByUuid->keys()->diff($localUuids);

        $matchIds = MatchArchetype::query()
            ->whereIn('archetype_id', $removed->pluck('id'))
            ->distinct()
            ->pluck('mtgo_match_id');

        // Surviving archetypes are the rename-successor candidates. Suggestions
        // only matter for removed archetypes with matches — those are the ones
        // the user decides on; matchless ones are deleted without a decision.
        $survivors = $local->filter(fn (Archetype $archetype) => $apiByUuid->has($archetype->uuid));

        return [
            'api' => $apiRows,
            'added' => $added->count(),
            'updated' => $updated->count(),
            'removed' => $removed->map(fn (Archetype $archetype) => [
                'id' => $archetype->id,
                'name' => $archetype->name,
                'format' => $archetype->format,
                'match_count' => $archetype->match_archetypes_count,
                'suggested_id' => $archetype->match_archetypes_count > 0
                    ? FindRenameCandidate::run($archetype, $survivors)?->id
                    : null,
            ])->all(),
            'match_ids' => $matchIds->all(),
        ];
    }
}
