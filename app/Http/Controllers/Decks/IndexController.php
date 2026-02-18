<?php

namespace App\Http\Controllers\Decks;

use App\Data\Front\DeckData;
use App\Models\Deck;
use Inertia\Inertia;
use Inertia\Response;

class IndexController
{
    public function __invoke(): Response
    {
        $decks = Deck::withCount(['wonMatches', 'lostMatches', 'matches'])
            ->withMax('matches', 'started_at')
            ->whereHas('matches')
            ->orderByDesc('matches_max_started_at')
            ->get()
            ->map(fn ($deck) => DeckData::from($deck));

        return Inertia::render('decks/Index', [
            'decks' => $decks,
        ]);
    }
}
