<?php

namespace App\Http\Controllers\Decks\SideboardGuides;

use App\Actions\SideboardGuides\EnsureSideboardGuide;
use App\Http\Controllers\Controller;
use App\Http\Requests\SideboardGuides\StoreSideboardGuideRequest;
use App\Models\Archetype;
use App\Models\Deck;
use Illuminate\Http\RedirectResponse;

class StoreController extends Controller
{
    public function __invoke(StoreSideboardGuideRequest $request, Deck $deck): RedirectResponse
    {
        $archetype = Archetype::query()->findOrFail($request->integer('archetype_id'));

        $guide = EnsureSideboardGuide::run($deck, $archetype);

        return redirect()->route('decks.sideboard-guides.edit', [$deck, $guide]);
    }
}
