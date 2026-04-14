<?php

namespace App\Http\Controllers\Import;

use App\Http\Controllers\Controller;
use App\Jobs\DiscoverGameLogsJob;
use App\Models\ImportScan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScanController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'deck_version_id' => 'required|integer|exists:deck_versions,id',
        ]);

        // Cancel any processing scan
        ImportScan::where('status', 'processing')->update(['status' => 'cancelled']);

        // Delete previous scans and their matches (cascade)
        ImportScan::query()->delete();

        $scan = ImportScan::create([
            'deck_version_id' => $validated['deck_version_id'],
            'status' => 'processing',
            'stage' => 'discovering',
        ]);

        DiscoverGameLogsJob::dispatch($scan->id);

        return response()->json(['scan_id' => $scan->id]);
    }
}
