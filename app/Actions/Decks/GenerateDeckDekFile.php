<?php

namespace App\Actions\Decks;

use App\Models\Card;
use App\Models\Deck;
use RuntimeException;

class GenerateDeckDekFile
{
    public static function run(Deck $deck): string
    {
        $version = $deck->latestVersion()->first();

        if (! $version) {
            throw new RuntimeException("Deck {$deck->id} has no versions to export.");
        }

        $cards = $version->cards;

        if (empty($cards)) {
            throw new RuntimeException("Deck {$deck->id} latest version has no cards.");
        }

        $mtgoIds = collect($cards)
            ->pluck('mtgo_id')
            ->filter()
            ->unique()
            ->values();

        $cardsByMtgoId = Card::whereIn('mtgo_id', $mtgoIds)
            ->get(['mtgo_id', 'name'])
            ->keyBy('mtgo_id');

        $lines = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<Deck xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">',
            '  <NetDeckID>0</NetDeckID>',
            '  <PreconstructedDeckID>0</PreconstructedDeckID>',
        ];

        foreach ($cards as $card) {
            $mtgoId = $card['mtgo_id'] ?? null;
            $resolved = $mtgoId ? $cardsByMtgoId->get($mtgoId) : null;

            if (! $resolved) {
                continue;
            }

            $sideboard = ((int) $card['sideboard']) === 1 ? 'true' : 'false';
            $name = htmlspecialchars($resolved->name, ENT_XML1, 'UTF-8');

            $lines[] = sprintf(
                '  <Cards CatID="%s" Quantity="%d" Sideboard="%s" Name="%s" Annotation="0"/>',
                $mtgoId,
                (int) $card['quantity'],
                $sideboard,
                $name,
            );
        }

        $lines[] = '</Deck>';

        return implode("\n", $lines);
    }
}
