<?php

namespace App\Http\Controllers\Decks;

use App\Http\Controllers\Controller;
use App\Models\Card;
use App\Models\Deck;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CoverArtOptionsController extends Controller
{
    public function __invoke(Deck $deck, Request $request): JsonResponse
    {
        $request->validate([
            'card_name' => 'required|string',
        ]);

        $cards = Card::where('name', $request->input('card_name'))
            ->whereNotNull('art_crop')
            ->where('art_crop', '!=', '')
            ->get(['id', 'name', 'set_name', 'set_code', 'art_crop']);

        return response()->json($cards);
    }
}
