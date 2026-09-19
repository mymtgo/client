<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Cards\CreateMissingCardsFromLocalData;
use App\Facades\Mtgo;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;

class RunPopulateCardsController extends Controller
{
    /**
     * Creating the stubs has to come first. PopulateMissingCardData only
     * enriches rows that already exist (it starts from
     * `Card::whereNull('name')`), so on a device whose history arrived by
     * cloud sync, where the cards table is empty, populating alone is a
     * no-op. The scan gives it something to work on.
     */
    public function __invoke(): RedirectResponse
    {
        try {
            CreateMissingCardsFromLocalData::run();
            Mtgo::populateMissingCardData(sync: true);
        } catch (\Throwable $e) {
            return back()->withErrors(['populateCards' => 'Card population failed: '.$e->getMessage()]);
        }

        return back();
    }
}
