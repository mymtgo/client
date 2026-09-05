<?php

namespace App\Http\Controllers\Decks\SideboardGuides;

use App\Actions\Decks\GetDeckViewSharedProps;
use App\Actions\Overlay\BuildSideboardGuide;
use App\Actions\Overlay\FetchCommunitySideboardRates;
use App\Actions\Overlay\GetArchetypeNotes;
use App\Actions\SideboardGuides\GetSideboardGuideSummaries;
use App\Enums\SideboardGuideScope;
use App\Http\Controllers\Controller;
use App\Models\Deck;
use App\Models\SideboardGuide;
use Inertia\Inertia;
use Inertia\Response;

class EditController extends Controller
{
    public function __invoke(Deck $deck, SideboardGuide $sideboardGuide): Response
    {
        $sideboardGuide->load(['archetype', 'cards']);
        $version = $deck->latestVersion;

        return Inertia::render('decks/SideboardGuideEdit', [
            ...GetDeckViewSharedProps::run($deck),
            'currentPage' => 'sideboard-guides',
            'guide' => GetSideboardGuideSummaries::forGuide($sideboardGuide),
            'hasVersion' => (bool) $version,
            'sideboard' => $version
                ? BuildSideboardGuide::run(
                    $version,
                    $sideboardGuide->archetype,
                    FetchCommunitySideboardRates::run($version, $sideboardGuide->archetype),
                    SideboardGuideScope::Editor,
                    $sideboardGuide,
                )
                : null,
            'notes' => GetArchetypeNotes::run($deck, $sideboardGuide->archetype),
        ]);
    }
}
