<?php

namespace App\Actions\Decks;

use App\Actions\Matches\DetermineMatchDeck;
use App\Models\Account;
use App\Models\Deck;
use App\Models\MtgoMatch;
use Illuminate\Support\Facades\DB;

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
                'account_id' => Account::active()->value('id'),
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

            ComputeDeckIdentity::run($deck);

            $deckIds[] = $deck->id;
        }

        // Batch cleanup and re-linking in a single transaction.
        DB::transaction(function () use ($deckIds) {
            $accountId = Account::active()->value('id');
            if ($accountId) {
                Deck::where('account_id', $accountId)->whereNotIn('id', $deckIds)->delete();

                // Backfill orphaned decks that were synced before the account existed
                Deck::whereNull('account_id')->whereIn('id', $deckIds)->update(['account_id' => $accountId]);
            } else {
                Deck::whereNotIn('id', $deckIds)->delete();
            }

            // Re-link complete matches that lost their deck association
            MtgoMatch::where('state', 'complete')
                ->whereNull('deck_version_id')
                ->each(fn (MtgoMatch $match) => DetermineMatchDeck::run($match));
        });
    }
}
