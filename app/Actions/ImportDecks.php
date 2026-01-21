<?php

namespace App\Actions;

use App\Actions\Decks\GetDeckFiles;

class ImportDecks
{
    public static function run(): void
    {
        $decks = GetDeckFiles::run();

        foreach ($decks as $deck) {
            CreateOrUpdateDeck::run($deck);
        }
    }
}
