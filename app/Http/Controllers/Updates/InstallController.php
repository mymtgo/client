<?php

namespace App\Http\Controllers\Updates;

use App\Actions\AutoUpdate\InstallDownloadedUpdate;
use Inertia\Inertia;
use Inertia\Response;

class InstallController
{
    public function __invoke(): Response
    {
        InstallDownloadedUpdate::run();

        return Inertia::render('updates/Install');
    }
}
