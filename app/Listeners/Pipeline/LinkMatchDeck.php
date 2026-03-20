<?php

namespace App\Listeners\Pipeline;

use App\Actions\Matches\DetermineMatchDeck;
use App\Events\DeckLinkedToMatch;
use App\Events\MatchJoined;
use App\Jobs\SyncDecks;
use App\Models\MtgoMatch;

class LinkMatchDeck
{
    public function handle(MatchJoined $event): void
    {
        $match = MtgoMatch::where('token', $event->logEvent->match_token)->first();

        if (! $match || $match->deck_version_id) {
            return;
        }

        DetermineMatchDeck::run($match);
        $match->refresh();

        if (! $match->deck_version_id) {
            SyncDecks::dispatchSync();
            DetermineMatchDeck::run($match);
            $match->refresh();
        }

        if ($match->deck_version_id) {
            DeckLinkedToMatch::dispatch($match);
        }
    }
}
