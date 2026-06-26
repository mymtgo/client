<?php

namespace App\Http\Controllers\Upgrade;

use App\Http\Controllers\Controller;
use App\Models\SchemaUpgrade;
use Illuminate\Http\JsonResponse;

class StatusController extends Controller
{
    public function __invoke(SchemaUpgrade $schemaUpgrade): JsonResponse
    {
        return response()->json([
            'status' => $schemaUpgrade->status,
            'stage' => $schemaUpgrade->stage,
            'progress' => $schemaUpgrade->progress,
            'total' => $schemaUpgrade->total,
            'error' => $schemaUpgrade->error,
        ]);
    }
}
