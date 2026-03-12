<?php

namespace App\Http\Controllers\Debug\Matches;

use App\Facades\Mtgo;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;

class ProcessController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        Mtgo::processLogEvents(force: true);

        return back();
    }
}
