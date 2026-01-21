<?php

namespace App\Actions;

use App\Models\Card;
use App\Models\Deck;

class CreateOrUpdateDeck
{
    public static function run(string $path): ?Deck
    {
        $xml = simplexml_load_file($path);

        $array = json_decode(json_encode($xml), true);

        if (! isset($array['@attributes']) || ! $array['@attributes']['NetDeckId']) {
            return null;
        }

        if (($array['@attributes']['GroupingType'] ?? null) != 'Deck') {
            return null;
        }

        $cards = collect($array['Item'])->map(function ($item) {
            return [
                'card_id' => Card::firstOrCreate([
                    'mtgo_id' => $item['@attributes']['CatId'],
                ])->id,
                'mtgo_id' => $item['@attributes']['CatId'],
                'quantity' => $item['@attributes']['Quantity'],
                'sideboard' => $item['@attributes']['IsSideboard'] == 'true',
            ];
        });

        $deckId = $array['@attributes']['NetDeckId'];

        $deck = Deck::where('mtgo_id', $deckId)->withTrashed()->first() ?: new Deck;

        $deck->fill([
            'mtgo_id' => $deckId,
            'name' => $array['@attributes']['Name'],
            'format' => $array['@attributes']['FormatCode'],
        ])->save();

        $deck->cards()->sync(
            $cards->mapWithKeys(function ($card) {
                return [$card['card_id'] => [
                    'quantity' => $card['quantity'],
                    'sideboard' => $card['sideboard'],
                ]];
            })
        );

        return $deck;
    }
}
