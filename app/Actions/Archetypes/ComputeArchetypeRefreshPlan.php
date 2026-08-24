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
     * `Http::mymtgoApi()` throws `OfflineModeException` (a `RuntimeException`)
     * when offline mode is on. That is deliberately left unhandled here: both
     * callers (`ShowController` and `ApplyController`) already catch
     * `\Throwable` around this call and redirect with an error message.
     *
     *
     * @return array{
     *     api: array<int, array{uuid: string, name: string, format: string, colorIdentity: string|null}>,
     *     added: int,
     *     added_rows: array<int, array{uuid: string, name: string, format: string, colorIdentity: string|null}>,
     *     updated: int,
     *     removed: array<int, array{id: int, name: string, format: string|null, match_count: int, suggested_id: int|null, suggested_uuid: string|null}>,
     *     match_ids: array<int, int>,
     * }
     *
     * @throws \RuntimeException
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

        $addedRows = collect($apiRows)
            ->filter(fn (array $row) => ! $localUuids->contains($row['uuid']))
            ->map(fn (array $row) => [
                'uuid' => $row['uuid'],
                'name' => $row['name'],
                'format' => strtolower($row['format']),
                'colorIdentity' => $row['colorIdentity'] ?? null,
            ])
            ->values();

        $matchIds = MatchArchetype::query()
            ->whereIn('archetype_id', $removed->pluck('id'))
            ->distinct()
            ->pluck('mtgo_match_id');

        // Rename-successor candidates are the surviving local archetypes PLUS
        // the incoming API archetypes (a server-side rekey removes and re-adds
        // an archetype in one refresh — the successor doesn't exist locally
        // yet). Incoming candidates are unsaved models carrying only a uuid.
        // Suggestions only matter for removed archetypes with matches — those
        // are the ones the user decides on; matchless ones are deleted without
        // a decision.
        $survivors = $local->filter(fn (Archetype $archetype) => $apiByUuid->has($archetype->uuid));

        $candidates = $survivors->concat($addedRows->map(fn (array $row) => (new Archetype)->forceFill([
            'uuid' => $row['uuid'],
            'name' => $row['name'],
            'format' => $row['format'],
            'color_identity' => $row['colorIdentity'],
        ])));

        return [
            'api' => $apiRows,
            'added' => $added->count(),
            'added_rows' => $addedRows->all(),
            'updated' => $updated->count(),
            'removed' => $removed->map(function (Archetype $archetype) use ($candidates) {
                $suggested = $archetype->match_archetypes_count > 0
                    ? FindRenameCandidate::run($archetype, $candidates)
                    : null;

                return [
                    'id' => $archetype->id,
                    'name' => $archetype->name,
                    'format' => $archetype->format,
                    'match_count' => $archetype->match_archetypes_count,
                    'suggested_id' => $suggested?->exists ? $suggested->id : null,
                    'suggested_uuid' => ($suggested && ! $suggested->exists) ? $suggested->uuid : null,
                ];
            })->all(),
            'match_ids' => $matchIds->all(),
        ];
    }
}
