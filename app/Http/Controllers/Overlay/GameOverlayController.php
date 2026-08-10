<?php

namespace App\Http\Controllers\Overlay;

use App\Actions\Cards\ComputeDrawOdds;
use App\Actions\Overlay\BuildSideboardGuide;
use App\Actions\Overlay\DetectSideboarding;
use App\Actions\Overlay\GetArchetypeNotes;
use App\Actions\Overlay\ResolveOverlayOpponent;
use App\Data\Front\ArchetypeData;
use App\Data\Front\OverlayOpponentData;
use App\Data\Front\SideboardGuideData;
use App\Enums\MatchState;
use App\Facades\AppSettings;
use App\Http\Controllers\Controller;
use App\Models\Archetype;
use App\Models\MtgoMatch;
use Inertia\Inertia;
use Inertia\Response;

class GameOverlayController extends Controller
{
    public function __invoke(): Response
    {
        $match = MtgoMatch::whereIn('state', [MatchState::Started, MatchState::InProgress])
            ->latest('started_at')
            ->first();

        $sections = [
            'opponent' => AppSettings::overlayShowOpponent(),
            'drawOdds' => AppSettings::overlayShowDrawOdds(),
            'sideboard' => AppSettings::overlayShowSideboard(),
        ];

        $opponent = $match && $sections['opponent']
            ? ResolveOverlayOpponent::run($match)
            : null;

        return Inertia::render('overlay/GameOverlay', [
            'sections' => $sections,
            'opponent' => $opponent,
            'format' => $match?->format,
            'archetypes' => Inertia::defer(fn () => ArchetypeData::collect(Archetype::orderBy('name')->get())),
            'drawOdds' => $match && $sections['drawOdds'] ? ComputeDrawOdds::run($match) : null,
            'sideboard' => $sections['sideboard'] ? $this->sideboardGuide($match, $opponent) : null,
            'notes' => $sections['sideboard'] ? $this->notes($match, $opponent) : ['current' => [], 'other' => []],
            'isSideboarding' => $match ? DetectSideboarding::run($match) : false,
        ]);
    }

    private function sideboardGuide(?MtgoMatch $match, ?OverlayOpponentData $opponent): ?SideboardGuideData
    {
        $archetype = $this->archetype($opponent);

        if (! $match?->deckVersion || ! $archetype) {
            return null;
        }

        return BuildSideboardGuide::run($match->deckVersion, $archetype);
    }

    /**
     * @return array{current: array, other: array}
     */
    private function notes(?MtgoMatch $match, ?OverlayOpponentData $opponent): array
    {
        $archetype = $this->archetype($opponent);
        $deck = $match?->deckVersion?->deck;

        if (! $deck || ! $archetype) {
            return ['current' => [], 'other' => []];
        }

        return GetArchetypeNotes::run($deck, $archetype);
    }

    private function archetype(?OverlayOpponentData $opponent): ?Archetype
    {
        if (! $opponent?->archetypeId) {
            return null;
        }

        return Archetype::query()->find($opponent->archetypeId);
    }
}
