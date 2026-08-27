<?php

namespace App\Http\Controllers\Matches;

use App\Actions\Decks\GetDeckViewSharedProps;
use App\Actions\Matches\BuildMatchShowProps;
use App\Http\Controllers\Controller;
use App\Models\DeckVersion;
use App\Models\MtgoMatch;
use Inertia\Inertia;

class ShowController extends Controller
{
    public function __invoke(string $id)
    {
        $match = MtgoMatch::with([
            'games.players',
            'games.timeline',
            'opponentArchetypes.archetype',
            'opponentArchetypes.player',
            'deck.cover',
            'deck.archetype',
            'league',
        ])->withCount([
            'games as games_won_count' => fn ($q) => $q->where('won', true),
            'games as games_lost_count' => fn ($q) => $q->where('won', false),
        ])->find($id);

        if (! $match) {
            return redirect()->route('home');
        }

        // Get deck sidebar props if match has a deck
        $deck = DeckVersion::find($match->deck_version_id)?->deck;
        $shared = $deck ? GetDeckViewSharedProps::run($deck) : [];

        return Inertia::render('matches/Show', [
            ...$shared,
            'currentPage' => 'matches',
            ...BuildMatchShowProps::run($match),
        ]);
    }
}
