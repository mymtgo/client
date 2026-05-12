<?php

namespace App\Http\Controllers\Archetypes;

use App\Actions\Archetypes\GetArchetypeVariantFacingWinrates;
use App\Actions\Archetypes\GetFilteredArchetypes;
use App\Data\Front\ArchetypeData;
use App\Data\Front\ArchetypeDeckData;
use App\Data\Front\ArchetypeDetailData;
use App\Http\Controllers\Controller;
use App\Models\Archetype;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EditController extends Controller
{
    public function __invoke(Request $request, Archetype $archetype): Response
    {
        $data = GetFilteredArchetypes::run($request);

        $archetype->load(['decks' => fn ($q) => $q->orderByDesc('seen_count'), 'decks.cards']);

        $winrates = GetArchetypeVariantFacingWinrates::run($archetype);

        $decks = $archetype->decks
            ->sortByDesc(fn ($deck) => [
                ($winrates[$deck->id]['wins'] ?? 0) + ($winrates[$deck->id]['losses'] ?? 0),
                $deck->seen_count,
            ])
            ->values()
            ->map(fn ($deck) => ArchetypeDeckData::fromModel($deck, $winrates[$deck->id] ?? null))
            ->all();

        $detail = new ArchetypeDetailData(
            archetype: ArchetypeData::fromModel($archetype),
            decks: $decks,
            isStale: $archetype->decklist_downloaded_at !== null
                && $archetype->decklist_downloaded_at->lt(now()->subWeek()),
        );

        return Inertia::render('archetypes/Edit', [
            'archetypes' => $data['archetypes'],
            'formats' => $data['formats'],
            'filters' => $data['filters'],
            'detail' => $detail,
        ]);
    }
}
