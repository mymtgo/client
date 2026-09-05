<?php

namespace App\Http\Controllers\Decks;

use App\Actions\Archetypes\GetArchetypeOptions;
use App\Actions\Decks\GetDeckViewSharedProps;
use App\Actions\SideboardGuides\GetSideboardGuideSummaries;
use App\Http\Controllers\Controller;
use App\Models\Deck;
use Inertia\Inertia;
use Inertia\Response;

class SideboardGuidesController extends Controller
{
    public function __invoke(Deck $deck): Response
    {
        return Inertia::render('decks/SideboardGuides', [
            ...GetDeckViewSharedProps::run($deck),
            'currentPage' => 'sideboard-guides',
            'guides' => GetSideboardGuideSummaries::run($deck),
            // Only the create dialog needs the full archetype list, so it loads
            // after first paint rather than delaying the page.
            'archetypes' => Inertia::defer(fn () => GetArchetypeOptions::run()),
        ]);
    }
}
