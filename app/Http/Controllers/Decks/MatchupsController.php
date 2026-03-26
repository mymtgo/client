<?php

namespace App\Http\Controllers\Decks;

use App\Actions\Decks\GetArchetypeMatchupSpread;
use App\Actions\Decks\GetDeckViewSharedProps;
use App\Http\Controllers\Controller;
use App\Models\Deck;
use Inertia\Inertia;

class MatchupsController extends Controller
{
    public function __invoke(Deck $deck)
    {
        $shared = GetDeckViewSharedProps::run($deck);

        $from = now()->subMonths(2)->startOfDay();
        $to = now()->endOfDay();

        return Inertia::render('decks/Matchups', [
            ...$shared,
            'currentPage' => 'matchups',
            'matchupSpread' => GetArchetypeMatchupSpread::run($deck, $from, $to),
        ]);
    }
}
