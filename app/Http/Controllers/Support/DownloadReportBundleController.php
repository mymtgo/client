<?php

namespace App\Http\Controllers\Support;

use App\Actions\Support\BuildSupportBundle;
use App\Exceptions\SupportBundleEmptyException;
use App\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DownloadReportBundleController extends Controller
{
    public function __invoke(BuildSupportBundle $build): BinaryFileResponse
    {
        try {
            $path = $build();
            $filename = 'mtgo-report-'.now()->format('Y-m-d-His').'.zip';

            return response()
                ->download($path, $filename, ['Content-Type' => 'application/zip'])
                ->deleteFileAfterSend(true);
        } catch (SupportBundleEmptyException) {
            abort(404, 'No log files available');
        }
    }
}
