<?php

namespace App\Listeners;

use App\Actions\Decks\OpenDeckPopoutWindow;
use App\Events\DeckLinkedToMatch;
use Native\Desktop\Facades\Settings;
use Native\Desktop\Facades\Window;

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
        $targetWindowId = "deck-popout-{$deckId}";

        // Close any other deck popout windows
        collect(Window::all())
            ->filter(fn ($w) => str_starts_with($w['id'], 'deck-popout-') && $w['id'] !== $targetWindowId)
            ->each(fn ($w) => Window::close($w['id']));

        OpenDeckPopoutWindow::run($deckId);
    }
}
