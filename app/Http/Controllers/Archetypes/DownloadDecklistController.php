<?php

namespace App\Http\Controllers\Archetypes;

use App\Actions\Archetypes\DownloadArchetypeDecklist;
use App\Models\Archetype;
use Illuminate\Http\JsonResponse;

class DownloadDecklistController
{
    public function __invoke(Archetype $archetype): JsonResponse
    {
        try {
            DownloadArchetypeDecklist::run($archetype);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true]);
    }
}
