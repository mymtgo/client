<?php

namespace App\Http\Controllers\Decks;

use App\Http\Controllers\Controller;
use App\Models\Card;
use App\Models\Deck;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class UpdateCoverArtController extends Controller
{
    public function __invoke(Deck $deck, Request $request): RedirectResponse
    {
        $request->validate([
            'cover_id' => 'nullable|exists:cards,id',
        ]);

        if ($request->filled('cover_id')) {
            $card = Card::where('id', $request->input('cover_id'))
                ->whereNotNull('art_crop')
                ->where('art_crop', '!=', '')
                ->firstOrFail();

            $deck->update(['cover_id' => $card->id]);
        } else {
            $deck->update(['cover_id' => null]);
        }

        return back();
    }
}
