<?php

namespace App\Http\Controllers\Cards;

use App\Facades\Mtgo;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;

class PopulateController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        try {
            Mtgo::populateMissingCardData(sync: true);
        } catch (\Throwable $e) {
            return back()->withErrors(['populateCards' => 'Card population failed: '.$e->getMessage()]);
        }

        return back();
    }
}
