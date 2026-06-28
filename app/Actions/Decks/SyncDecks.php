<?php

namespace App\Actions\Decks;

use App\Actions\DetermineDeckArchetype;
use App\Actions\Matches\RelinkOrphanMatches;
use App\Models\Account;
use App\Models\Deck;
use App\Support\TimedTransaction;
use Illuminate\Support\Facades\Log;

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

            /**
             * Get the latest version of this deck.
             */
            $deckVersion = $deck->versions()->orderBy('modified_at', 'desc')->first();

            if ($deckVersion?->modified_at?->gte($fileModified)) {

                $deckIds[] = $deck->id;

                continue;
            }

            $cards = collect($array['Item'] ?? [])->map(function ($item) {
                $attrs = $item['@attributes'] ?? $item;

                return [
                    'mtgo_id' => (int) $attrs['CatId'],
                    'quantity' => (int) $attrs['Quantity'],
                    'sideboard' => filter_var($attrs['IsSideboard'], FILTER_VALIDATE_BOOL) ? 'true' : 'false',
                ];
            });

            $signature = GenerateDeckSignature::run($cards);

            $accountId = Account::active()->value('id');

            $fill = [
                'mtgo_id' => $attributes['NetDeckId'],
                'format' => $attributes['FormatCode'],
                'account_id' => $deck->account_id ?? $accountId,
                'updated_at' => $attributes['Timestamp'],
            ];

            // Preserve user-set custom names. `original_name` is only populated
            // once the user has manually renamed the deck, so its presence
            // signals that the MTGO XML name should not overwrite ours.
            if (! $deck->original_name) {
                $fill['name'] = $attributes['Name'];
            }

            $deck->fill($fill);

            $deck->save();

            /**
             * Do we already have this variation of the deck?
             */
            $deckVersion = $deck->versions()->where('signature', $signature)->firstOrCreate([
                'signature' => $signature,
            ], [
                'modified_at' => $fileModified,
            ]);

            // If we don't have any games for this deck version and it's not the one we just created,
            // it's possible the user has been actively changing the deck, so just remove the orphaned versions.
            $deck->versions()->whereDoesntHave('matches')->where('id', '!=', $deckVersion->id)->delete();

            try {
                ComputeDeckIdentity::run($deck);
            } catch (\Throwable $e) {
                Log::warning('Failed to compute deck identity: '.$e->getMessage(), [
                    'deck_id' => $deck->id,
                ]);
            }
            static::prefillArchetype($deck);

            $deckIds[] = $deck->id;
        }

        // Backfill account_id on orphaned decks that were synced before the
        // active account existed. We intentionally never auto-delete decks
        // here: the XML scan can return empty for legitimate reasons (MTGO
        // closed, second MTGO account active, transient I/O), and treating
        // that as "user removed every deck" wipes the entire local history.
        TimedTransaction::run('SyncDecks:backfill', function () use ($deckIds) {
            $accountId = Account::active()->value('id');

            if ($accountId) {
                Deck::whereNull('account_id')->whereIn('id', $deckIds)->update(['account_id' => $accountId]);
            }
        });

        // Re-link complete matches that lost (or never got) their deck association.
        // Kept outside the transaction above to avoid holding the SQLite write lock
        // across the loop.
        RelinkOrphanMatches::run(limit: 200);
    }

    /**
     * Attempt to auto-detect and set the archetype for a deck
     * using the estimate API. Only runs when archetype_id is null.
     */
    public static function prefillArchetype(Deck $deck): void
    {
        if ($deck->archetype_id !== null) {
            return;
        }

        $latestVersion = $deck->latestVersion;

        if (! $latestVersion) {
            return;
        }

        $cards = collect($latestVersion->cards)->map(fn ($card) => [
            'mtgo_id' => $card['oracle_id'] ?? $card['mtgo_id'] ?? null,
            'quantity' => (int) ($card['quantity'] ?? 1),
        ])->filter(fn ($card) => $card['mtgo_id'] !== null);

        if ($cards->isEmpty()) {
            return;
        }

        try {
            $result = DetermineDeckArchetype::run($cards, $deck->format);

            if ($result) {
                $deck->update(['archetype_id' => $result['archetype_id']]);
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to prefill deck archetype: '.$e->getMessage());
        }
    }
}
