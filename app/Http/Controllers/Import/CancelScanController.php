<?php

namespace App\Http\Controllers\Import;

use App\Http\Controllers\Controller;
use App\Models\ImportScan;
use Illuminate\Http\Response;

class CancelScanController extends Controller
{
    public function __invoke(ImportScan $scan): Response
    {
        if ($scan->isProcessing()) {
            $scan->update(['status' => 'cancelled']);
        } else {
            $scan->delete();
        }

        return response()->noContent();
    }
}
