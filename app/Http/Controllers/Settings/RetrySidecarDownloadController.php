<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Sidecar\StartSidecarSupervisor;
use App\Http\Controllers\Controller;
use App\Sidecar\SidecarPaths;
use Illuminate\Http\RedirectResponse;

class RetrySidecarDownloadController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        if (SidecarPaths::supported()) {
            StartSidecarSupervisor::startDownload();
        }

        return back();
    }
}
