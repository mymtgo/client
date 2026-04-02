<?php

namespace App\Http\Controllers\Archetypes;

use App\Actions\Archetypes\GetFilteredArchetypes;
use App\Data\Front\ArchetypeData;
use App\Data\Front\CardData;
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

        $cards = null;

        if ($archetype->decklist_downloaded_at) {
            $archetype->load('cards');
            $cards = $archetype->cards->map(fn ($card) => CardData::fromModel($card))->all();
        }

        return Inertia::render('archetypes/Edit', [
            'archetypes' => $data['archetypes'],
            'formats' => $data['formats'],
            'filters' => $data['filters'],
            'archetype' => ArchetypeData::fromModel($archetype),
            'cards' => $cards,
        ]);
    }
}
