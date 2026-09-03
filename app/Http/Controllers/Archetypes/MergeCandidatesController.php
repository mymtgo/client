<?php

namespace App\Http\Controllers\Archetypes;

use App\Data\Front\ArchetypeData;
use App\Models\Archetype;
use Illuminate\Http\JsonResponse;

class MergeCandidatesController
{
    public function __invoke(Archetype $archetype): JsonResponse
    {
        $candidates = Archetype::query()
            ->where('format', $archetype->format)
            ->whereKeyNot($archetype->id)
            ->where('is_fallback', false)
            ->whereNull('merged_into_id')
            ->withExists('decks')
            ->orderBy('name')
            ->get();

        return response()->json(
            $candidates->map(fn (Archetype $a) => ArchetypeData::fromModel($a))->all(),
        );
    }
}
