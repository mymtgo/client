<?php

namespace App\Http\Controllers\Decks;

use App\Actions\RegenerateCardGameStats;
use App\Http\Controllers\Controller;
use App\Models\Deck;
use Illuminate\Http\RedirectResponse;

class RegenerateCardStatsController extends Controller
{
    public function __invoke(Deck $deck): RedirectResponse
    {
        $result = RegenerateCardGameStats::forDeck($deck);

        return back()->with('cardStatsRegenerated', $result['queued']);
    }
}
