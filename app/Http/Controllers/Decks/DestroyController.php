<?php

namespace App\Http\Controllers\Decks;

use App\Http\Controllers\Controller;
use App\Http\Requests\DeleteDeckRequest;
use App\Models\Deck;
use Illuminate\Http\RedirectResponse;

class DestroyController extends Controller
{
    /**
     * Soft delete the deck. Matches, versions and stats are left intact so a
     * restore brings the deck's whole history back with it.
     */
    public function __invoke(Deck $deck, DeleteDeckRequest $request): RedirectResponse
    {
        if (! $deck->trashed()) {
            $deck->delete();
        }

        return to_route('decks.index');
    }
}
