<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Settings\ValidatePath;
use App\Facades\Mtgo;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Native\Desktop\Facades\Settings;

class RunIngestController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        $logOk = ValidatePath::forLogs(Settings::get('log_path', ''));
        $dataOk = ValidatePath::forData(Settings::get('log_data_path', ''));

        if (! $logOk['valid'] || ! $dataOk['valid']) {
            return back()->withErrors(['ingest' => 'File paths are invalid. Fix them before running ingestion.']);
        }

        try {
            Mtgo::ingestLogs();
            Cache::put('settings.last_ingest_at', now()->toISOString(), now()->addDay());
        } catch (\Throwable $e) {
            return back()->withErrors(['ingest' => 'Ingestion failed: '.$e->getMessage()]);
        }

        return back();
    }
}
