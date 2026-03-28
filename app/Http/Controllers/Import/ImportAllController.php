<?php

namespace App\Http\Controllers\Import;

use App\Http\Controllers\Controller;
use App\Jobs\ImportMatchesJob;
use App\Models\ImportScan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ImportAllController extends Controller
{
    public function __invoke(Request $request, ImportScan $scan): JsonResponse
    {
        $query = $scan->matches();

        if ($request->has('format') && $request->get('format') !== '') {
            $query->where('format_display', $request->get('format'));
        }

        if ($request->has('min_confidence') && $request->get('min_confidence') !== '') {
            $min = (float) $request->get('min_confidence');
            if ($min > 0) {
                $query->whereNotNull('confidence')->where('confidence', '>=', $min);
            }
        }

        $historyIds = $query->pluck('history_id')->toArray();

        ImportMatchesJob::dispatch($scan->id, $historyIds);

        return response()->json([
            'dispatched' => count($historyIds),
        ]);
    }
}
