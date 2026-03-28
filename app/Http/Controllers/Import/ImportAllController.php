<?php

namespace App\Http\Controllers\Import;

use App\Actions\Import\ImportMatches;
use App\Http\Controllers\Controller;
use App\Models\ImportScan;
use Illuminate\Http\JsonResponse;

class ImportAllController extends Controller
{
    public function __invoke(ImportScan $scan): JsonResponse
    {
        $result = ImportMatches::runFromScan($scan);

        return response()->json($result);
    }
}
