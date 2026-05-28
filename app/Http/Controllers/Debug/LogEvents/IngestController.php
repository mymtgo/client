<?php

namespace App\Http\Controllers\Debug\LogEvents;

use App\Http\Controllers\Controller;
use App\Jobs\IngestLogs;
use Illuminate\Http\RedirectResponse;

class IngestController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        IngestLogs::dispatch();

        return back();
    }
}
