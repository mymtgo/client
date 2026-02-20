<?php

namespace App\Http\Controllers\Settings;

use App\Facades\Mtgo;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;

class RunPopulateCardsController extends Controller
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
