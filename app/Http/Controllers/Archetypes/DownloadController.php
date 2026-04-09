<?php

namespace App\Http\Controllers\Archetypes;

use App\Jobs\DownloadArchetypes;
use Illuminate\Http\RedirectResponse;

class DownloadController
{
    public function __invoke(): RedirectResponse
    {
        DownloadArchetypes::dispatchSync();

        return back();
    }
}
