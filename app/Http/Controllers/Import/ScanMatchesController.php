<?php

namespace App\Http\Controllers\Import;

use App\Http\Controllers\Controller;
use App\Models\ImportScan;
use App\Models\ImportScanMatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScanMatchesController extends Controller
{
    public function __invoke(Request $request, ImportScan $scan): JsonResponse
    {
        $perPage = min((int) $request->get('per_page', 50), 100);

        $sortField = $request->get('sort', 'confidence');
        $sortDir = $request->get('dir', 'desc');

        $query = $scan->matches();

        if ($sortField === 'confidence') {
            // Nulls last: sort non-null first, then by confidence
            $query->orderByRaw('confidence IS NULL ASC')
                ->orderBy('confidence', $sortDir);
        } elseif (in_array($sortField, ['started_at', 'opponent', 'format_display', 'outcome'])) {
            $query->orderBy($sortField, $sortDir);
        } else {
            $query->orderByDesc('started_at');
        }

        if ($request->has('format') && $request->get('format') !== '') {
            $query->where('format_display', $request->get('format'));
        }

        if ($request->has('min_confidence') && $request->get('min_confidence') !== '') {
            $min = (float) $request->get('min_confidence');
            if ($min > 0) {
                // Exclude null-confidence matches (no game log = can't verify deck)
                $query->whereNotNull('confidence')->where('confidence', '>=', $min);
            }
        }

        $matches = $query
            ->paginate($perPage)
            ->through(fn (ImportScanMatch $m) => [
                'id' => $m->id,
                'history_id' => $m->history_id,
                'started_at' => $m->started_at->toIso8601String(),
                'opponent' => $m->opponent,
                'format' => $m->format_display,
                'games_won' => $m->games_won,
                'games_lost' => $m->games_lost,
                'outcome' => $m->outcome,
                'confidence' => $m->confidence,
                'game_log_token' => $m->game_log_token,
                'round' => $m->round,
                'description' => $m->description,
            ]);

        return response()->json($matches);
    }
}
