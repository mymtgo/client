<?php

namespace App\Http\Controllers\Archetypes;

use App\Actions\Archetypes\DownloadArchetypeDecklist;
use App\Exceptions\OfflineModeException;
use App\Models\Archetype;
use Illuminate\Http\JsonResponse;

class DownloadDecklistController
{
    public function __invoke(Archetype $archetype): JsonResponse
    {
        if ($archetype->is_fallback) {
            abort(403, 'Fallback archetypes have no decklist to download.');
        }

        try {
            DownloadArchetypeDecklist::run($archetype);
        } catch (OfflineModeException) {
            return response()->json(['error' => 'Offline mode is enabled. Turn it off in Settings to download decklists.'], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true]);
    }
}
