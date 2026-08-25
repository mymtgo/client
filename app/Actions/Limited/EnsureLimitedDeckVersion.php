<?php

namespace App\Actions\Limited;

use App\Actions\Decks\GenerateDeckSignature;
use App\Models\Account;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Models\League;
use App\Models\LimitedDeckSnapshot;

class EnsureLimitedDeckVersion
{
    public const FORMAT = 'Limited';

    /**
     * Limited decks never exist as MTGO XML, so DetermineMatchDeck would
     * never find a version by signature. Synthesise one Deck per league
     * and one DeckVersion per distinct registered signature.
     *
     * The version's signature is recomputed from the snapshot's cards here
     * rather than trusting `$snapshot->signature` verbatim: it keeps the
     * version keyed on the exact same normalisation DetermineMatchDeck
     * relies on, immune to whatever the snapshot row happens to carry.
     */
    public static function run(League $league, LimitedDeckSnapshot $snapshot): DeckVersion
    {
        $key = $league->draft?->draft_token ?? "league-{$league->id}";
        $name = trim(($league->set_code ?? 'Limited').' Draft '.($league->started_at ?? now())->toLocal()->format('j M Y'));

        $deck = Deck::withTrashed()->firstOrCreate(
            ['mtgo_id' => "limited:{$key}"],
            [
                'name' => $name,
                'original_name' => $name,
                'format' => self::FORMAT,
                'account_id' => Account::currentId(),
            ],
        );

        $signature = GenerateDeckSignature::run(collect($snapshot->cards)->map(fn (array $card) => [
            'mtgo_id' => $card['catalog_id'],
            'quantity' => $card['quantity'],
            'sideboard' => $card['sideboard'] ? 'true' : 'false',
        ]));

        $version = $deck->versions()->firstOrCreate(
            ['signature' => $signature],
            ['modified_at' => $snapshot->captured_at],
        );

        $league->update(['deck_version_id' => $version->id]);

        return $version;
    }
}
