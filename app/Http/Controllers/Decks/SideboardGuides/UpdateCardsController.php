<?php

namespace App\Http\Controllers\Decks\SideboardGuides;

use App\Actions\SideboardGuides\SaveSideboardGuideCards;
use App\Http\Controllers\Controller;
use App\Http\Requests\SideboardGuides\UpdateSideboardGuideCardsRequest;
use App\Models\Deck;
use App\Models\SideboardGuide;
use Illuminate\Http\RedirectResponse;

class UpdateCardsController extends Controller
{
    public function __invoke(UpdateSideboardGuideCardsRequest $request, Deck $deck, SideboardGuide $sideboardGuide): RedirectResponse
    {
        SaveSideboardGuideCards::run($sideboardGuide, $request->cards());

        return back();
    }
}
