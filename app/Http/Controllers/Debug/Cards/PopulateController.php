<?php

namespace App\Http\Controllers\Debug\Cards;

use App\Http\Controllers\Controller;
use App\Jobs\PopulateMissingCardData;
use Illuminate\Http\RedirectResponse;

class PopulateController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        PopulateMissingCardData::dispatchSync();

        return back();
    }
}
