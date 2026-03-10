<?php

namespace App\Listeners;

use App\Events\DeckLinkedToMatch;
use App\Events\DeckPopoutRequested;
use Native\Desktop\Facades\Settings;

class OpenDeckPopoutOnMatch
{
    public function handle(DeckLinkedToMatch $event): void
    {
        if (! Settings::get('deck_popout_enabled')) {
            return;
        }

        $match = $event->match;

        if (! $match->deck_version_id) {
            return;
        }

        $deckId = $match->deckVersion->deck_id;

        DeckPopoutRequested::dispatch($deckId);
    }
}
