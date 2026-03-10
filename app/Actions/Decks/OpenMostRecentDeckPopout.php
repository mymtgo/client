<?php

namespace App\Actions\Decks;

use App\Models\Deck;

class OpenMostRecentDeckPopout
{
    public static function run(): void
    {
        $deck = Deck::forActiveAccount()
            ->latest('updated_at')
            ->first();

        if (! $deck) {
            return;
        }

        OpenDeckPopoutWindow::run($deck->id);
    }
}
