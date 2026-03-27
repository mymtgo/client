<?php

namespace App\Http\Controllers\Import;

use App\Actions\Import\ParseImportableMatches;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class ScanController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $matches = ParseImportableMatches::run();

        return response()->json([
            'matches' => $matches,
        ]);
    }
}
