<?php

namespace App\Http\Controllers\Archetypes;

use App\Actions\Archetypes\GetArchetypeWinrates;
use App\Actions\Archetypes\GetFilteredArchetypes;
use App\Data\Front\ArchetypeData;
use App\Data\Front\ArchetypeDetailData;
use App\Data\Front\CardData;
use App\Models\Archetype;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ShowController
{
    public function __invoke(Request $request, Archetype $archetype): Response
    {
        $sidebar = GetFilteredArchetypes::run($request);

        $cards = null;
        if ($archetype->decklist_downloaded_at) {
            $archetype->loadMissing('cards');
            $cards = $archetype->cards->map(function ($card) {
                $cardData = CardData::fromModel($card);
                $cardData->quantity = $card->pivot->quantity;
                $cardData->sideboard = $card->pivot->sideboard;

                return $cardData;
            })->all();
        }

        $winrates = GetArchetypeWinrates::run($archetype);

        $detail = new ArchetypeDetailData(
            archetype: ArchetypeData::fromModel($archetype),
            cards: $cards,
            playingWinrate: $winrates['playing']['winrate'] ?? null,
            playingRecord: $winrates['playing'] ? $winrates['playing']['wins'].' - '.$winrates['playing']['losses'] : null,
            facingWinrate: $winrates['facing']['winrate'] ?? null,
            facingRecord: $winrates['facing'] ? $winrates['facing']['wins'].' - '.$winrates['facing']['losses'] : null,
            isStale: $archetype->decklist_downloaded_at !== null
                && $archetype->decklist_downloaded_at->lt(now()->subWeek()),
        );

        return Inertia::render('archetypes/Show', [
            'detail' => $detail,
            ...$sidebar,
        ]);
    }
}
