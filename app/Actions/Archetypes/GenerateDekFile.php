<?php

namespace App\Actions\Archetypes;

use App\Models\Archetype;

class GenerateDekFile
{
    public static function run(Archetype $archetype): string
    {
        $archetype->loadMissing('cards');

        $lines = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<Deck xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">',
            '  <NetDeckID>0</NetDeckID>',
            '  <PreconstructedDeckID>0</PreconstructedDeckID>',
        ];

        foreach ($archetype->cards as $card) {
            $sideboard = $card->pivot->sideboard ? 'true' : 'false';
            $name = htmlspecialchars($card->name, ENT_XML1, 'UTF-8');
            $lines[] = sprintf(
                '  <Cards CatID="%s" Quantity="%d" Sideboard="%s" Name="%s" Annotation="0"/>',
                $card->mtgo_id,
                $card->pivot->quantity,
                $sideboard,
                $name,
            );
        }

        $lines[] = '</Deck>';

        return implode("\n", $lines);
    }
}
