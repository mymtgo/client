<?php

namespace App\Http\Controllers\Debug\LogEvents;

use App\Facades\Mtgo;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;

class IngestController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        Mtgo::ingestLogs();

        return back();
    }
}
