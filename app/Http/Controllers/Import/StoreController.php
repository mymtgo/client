<?php

namespace App\Http\Controllers\Import;

use App\Http\Controllers\Controller;
use App\Jobs\ImportMatchesJob;
use App\Models\ImportScan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StoreController extends Controller
{
    public function __invoke(Request $request, ImportScan $scan): JsonResponse
    {
        $validated = $request->validate([
            'history_ids' => 'required|array|min:1',
            'history_ids.*' => 'required|integer',
        ]);

        ImportMatchesJob::dispatch($scan->id, $validated['history_ids']);

        return response()->json([
            'dispatched' => count($validated['history_ids']),
        ]);
    }
}
