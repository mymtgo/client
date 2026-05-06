<?php

namespace App\Http\Controllers\Decks;

use App\Actions\QueueArchetypeDetectionForDeck;
use App\Http\Controllers\Controller;
use App\Http\Requests\TriggerArchetypeDetectionRequest;
use App\Models\Deck;
use Illuminate\Http\RedirectResponse;

class TriggerArchetypeDetectionController extends Controller
{
    public function __invoke(
        Deck $deck,
        TriggerArchetypeDetectionRequest $request,
        QueueArchetypeDetectionForDeck $queue,
    ): RedirectResponse {
        abort_if($deck->trashed(), 403, 'This deck has been deleted on MTGO and is read-only.');

        $count = $queue($deck, $request->string('filter_archetype')->toString());

        return back()->with('archetypeDetectionQueued', $count);
    }
}
