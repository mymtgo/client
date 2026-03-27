<?php

namespace App\Http\Controllers\Import;

use App\Actions\Import\ImportMatches;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StoreController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'matches' => 'required|array|min:1',
            'matches.*.history_id' => 'required|integer',
            'matches.*.started_at' => 'required|string',
            'matches.*.opponent' => 'required|string',
            'matches.*.format_raw' => 'required|string',
            'matches.*.games_won' => 'required|integer',
            'matches.*.games_lost' => 'required|integer',
            'matches.*.outcome' => 'required|string',
            'matches.*.round' => 'integer',
            'matches.*.has_game_log' => 'required|boolean',
            'matches.*.game_log_token' => 'nullable|string',
            'matches.*.local_player' => 'nullable|string',
            'matches.*.games' => 'nullable|array',
            'matches.*.local_cards' => 'nullable|array',
            'matches.*.game_ids' => 'nullable|array',
            'matches.*.deck_version_id' => 'nullable|integer|exists:deck_versions,id',
        ]);

        $result = ImportMatches::run($validated['matches']);

        return response()->json($result);
    }
}
