<?php

namespace App\Http\Controllers\Overlay;

use App\Actions\Archetypes\GetArchetypeOptions;
use App\Actions\Cards\ComputeDrawOdds;
use App\Actions\Overlay\BuildSideboardGuide;
use App\Actions\Overlay\DetectSideboarding;
use App\Actions\Overlay\FetchCommunitySideboardRates;
use App\Actions\Overlay\GetArchetypeNotes;
use App\Actions\Overlay\GetOpponentReveals;
use App\Actions\Overlay\ResolveOverlayOpponent;
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
            'reveals' => AppSettings::overlayShowReveals(),
        ];

        // The opponent is resolved whenever ANY section that derives from it is
        // enabled, not just the opponent header: the sideboard guide and its
        // notes are both keyed on the opponent's archetype, so gating
        // resolution on the header would empty them whenever the header is
        // switched off. Only the prop itself is gated on the header setting.
        $opponent = $match && ($sections['opponent'] || $sections['sideboard'])
            ? ResolveOverlayOpponent::run($match)
            : null;

        $archetype = $opponent?->archetypeId
            ? Archetype::query()->find($opponent->archetypeId)
            : null;

        return Inertia::render('overlay/GameOverlay', [
            'sections' => $sections,
            'opponent' => $sections['opponent'] ? $opponent : null,
            'format' => $match ? MtgoMatch::displayFormat($match->format) : null,
            'archetypes' => Inertia::defer(fn () => GetArchetypeOptions::run()),
            // Deferred, not a plain value: the 5s poll excludes `drawOdds`, but
            // a plain prop is still evaluated while the props array is built —
            // before Inertia filters on `only` — so every tick would rebuild
            // the hypergeometric data and throw it away. Deferring (rather
            // than Inertia::optional) keeps first paint populated: Inertia
            // fetches deferred props automatically after the initial render,
            // and `router.reload({ only: ['drawOdds'] })` resolves them too.
            'drawOdds' => Inertia::defer(
                fn () => $match && $sections['drawOdds'] ? ComputeDrawOdds::run($match) : null
            ),
            'reveals' => $match && $sections['reveals'] ? GetOpponentReveals::run($match) : null,
            'sideboard' => $sections['sideboard'] ? $this->sideboardGuide($match, $archetype) : null,
            'notes' => $sections['sideboard'] ? $this->notes($match, $archetype) : ['current' => [], 'other' => []],
            'isSideboarding' => $match && $sections['sideboard'] ? DetectSideboarding::run($match) : false,
            // Plain facts about the match itself, independent of any section
            // toggle — the page must not infer "no match/deck" from a section
            // being switched off (drawOdds/sideboard can be null either
            // because the section is disabled or because no archetype has
            // resolved yet; those are different states for the empty-state UI).
            'hasMatch' => (bool) $match,
            'hasDeck' => (bool) $match?->deckVersion,
            // Also a plain fact, for the same reason: the sideboard panel must
            // not read "pick an archetype" off a null `opponent` prop, which is
            // null whenever the opponent header is switched off.
            'hasArchetype' => (bool) $archetype,
        ]);
    }

    private function sideboardGuide(?MtgoMatch $match, ?Archetype $archetype): ?SideboardGuideData
    {
        if (! $match?->deckVersion || ! $archetype) {
            return null;
        }

        return BuildSideboardGuide::run(
            $match->deckVersion,
            $archetype,
            FetchCommunitySideboardRates::run($match->deckVersion, $archetype),
        );
    }

    /**
     * @return array{current: array, other: array}
     */
    private function notes(?MtgoMatch $match, ?Archetype $archetype): array
    {
        $deck = $match?->deckVersion?->deck;

        if (! $deck || ! $archetype) {
            return ['current' => [], 'other' => []];
        }

        return GetArchetypeNotes::run($deck, $archetype);
    }
}
