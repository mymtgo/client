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
            ->get(['id', 'name', 'set_name', 'set_code', 'art_crop', 'local_art_crop'])
            ->map(fn (Card $card) => [
                'id' => $card->id,
                'name' => $card->name,
                'set_name' => $card->set_name,
                'set_code' => $card->set_code,
                'art_crop' => $card->art_crop_url,
            ]);

        return response()->json($cards);
    }
}
