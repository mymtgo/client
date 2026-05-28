<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Settings\ValidatePath;
use App\Facades\AppSettings;
use App\Http\Controllers\Controller;
use App\Jobs\IngestLogs;
use Illuminate\Http\RedirectResponse;

class RunIngestController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        $logOk = ValidatePath::forLogs(AppSettings::logPath());
        $dataOk = ValidatePath::forData(AppSettings::logDataPath());

        if (! $logOk['valid'] || ! $dataOk['valid']) {
            return back()->withErrors(['ingest' => 'File paths are invalid. Fix them before running ingestion.']);
        }

        IngestLogs::dispatch();

        return back();
    }
}
