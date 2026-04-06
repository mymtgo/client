<?php

namespace App\Http\Controllers\Import;

use App\Http\Controllers\Controller;
use App\Models\ImportScan;
use Illuminate\Http\JsonResponse;

class ScanStatusController extends Controller
{
    public function __invoke(ImportScan $scan): JsonResponse
    {
        $data = [
            'status' => $scan->status,
            'stage' => $scan->stage,
            'progress' => $scan->progress,
            'total' => $scan->total,
            'error' => $scan->error,
        ];

        if ($scan->isComplete()) {
            $matches = $scan->matches();
            $data['match_count'] = $matches->count();
            $data['formats'] = $scan->matches()
                ->select('format_display')
                ->distinct()
                ->orderBy('format_display')
                ->pluck('format_display')
                ->toArray();
            $data['confidence_stats'] = [
                'high' => $scan->matches()->where('confidence', '>=', 0.6)->count(),
                'low' => $scan->matches()->where('confidence', '<', 0.6)->whereNotNull('confidence')->count(),
                'none' => $scan->matches()->whereNull('confidence')->count(),
            ];
        }

        return response()->json($data);
    }
}
