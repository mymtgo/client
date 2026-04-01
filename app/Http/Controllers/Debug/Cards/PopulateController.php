<?php

namespace App\Http\Controllers\Debug\Cards;

use App\Http\Controllers\Controller;
use App\Jobs\BackfillCardDetails;
use App\Jobs\PopulateMissingCardData;
use Illuminate\Http\RedirectResponse;

class PopulateController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        PopulateMissingCardData::dispatchSync();
        BackfillCardDetails::dispatchSync();

        return back();
    }
}
