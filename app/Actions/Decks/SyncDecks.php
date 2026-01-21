<?php

namespace App\Actions\Decks;

use App\Models\Deck;

class SyncDecks
{
    public static function run()
    {
        $deckFiles = GetDeckFiles::run();

        $deckIds = [];

        foreach ($deckFiles as $deckFile) {
            $xml = simplexml_load_file($deckFile);

            $array = json_decode(json_encode($xml), true);

            if (($array['@attributes']['GroupingType'] ?? null) != 'Deck') {
                continue;
            }

            $attributes = $array['@attributes'];

            $deck = Deck::where('mtgo_id', $attributes['NetDeckId'])->withTrashed()->first() ?: new Deck;

            $fileModified = now()->parse($attributes['Timestamp'])->startOfSecond();

            if ($deck->deleted_at) {
                $deck->restore();
            }

            /**
             * Get the latest version of this deck.
             */
            $deckVersion = $deck->versions()->orderBy('modified_at', 'desc')->first();

            if ($deckVersion?->modified_at?->gte($fileModified)) {

                $deckIds[] = $deck->id;

                continue;
            }

            $cards = collect($array['Item'])->map(function ($item) {
                $attrs = $item['@attributes'] ?? $item;

                return [
                    'mtgo_id' => $attrs['CatId'],
                    'quantity' => $attrs['Quantity'],
                    'sideboard' => $attrs['IsSideboard'],
                ];
            });

            $signature = GenerateDeckSignature::run($cards);

            $deck->fill([
                'mtgo_id' => $attributes['NetDeckId'],
                'name' => $attributes['Name'],
                'format' => $attributes['FormatCode'],
                'updated_at' => $attributes['Timestamp'],
            ]);

            $deck->save();

            /**
             * Do we already have this variation of the deck?
             */
            $deck->versions()->where('signature', $signature)->firstOrCreate([
                'signature' => $signature,
            ], [
                'modified_at' => $fileModified,
            ]);

            $deckIds[] = $deck->id;
        }

        Deck::whereNotIn('id', $deckIds)->delete();
    }
}
