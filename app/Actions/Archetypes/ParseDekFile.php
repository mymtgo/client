<?php

namespace App\Actions\Archetypes;

class ParseDekFile
{
    /**
     * Parse a .dek XML string into an array of card entries.
     *
     * @return array<int, array{mtgo_id: int, quantity: int, sideboard: bool}>
     */
    public static function run(string $xml): array
    {
        libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);

        if ($doc === false) {
            throw new \RuntimeException('Invalid .dek file: could not parse XML.');
        }

        $cards = [];

        foreach ($doc->Cards as $card) {
            $cards[] = [
                'mtgo_id' => (int) $card['CatID'],
                'quantity' => (int) $card['Quantity'],
                'sideboard' => strtolower((string) $card['Sideboard']) === 'true',
            ];
        }

        return $cards;
    }
}
