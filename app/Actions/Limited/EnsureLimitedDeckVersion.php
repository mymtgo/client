<?php

namespace App\Actions\Limited;

use App\Actions\Decks\GenerateDeckSignature;
use App\Actions\Limited\Read\BuildLimitedCardRows;
use App\Actions\Limited\Read\GetLimitedEventSharedProps;
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
        $key = self::keyFor($league);
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

    /**
     * The synthetic limited deck's key for $league. The only source of truth
     * for this derivation; {@see GetLimitedEventSharedProps}
     * and {@see BuildLimitedCardRows} look the deck
     * up by this same key rather than deriving their own.
     */
    public static function keyFor(League $league): string
    {
        return match (true) {
            $league->draft?->draft_token !== null => $league->draft->draft_token,

            // Leagues are unique on (event_id, mtgo_course_id) and both come from
            // MTGO, so this key is identical on every device that saw the event.
            $league->event_id !== null && $league->mtgo_course_id !== null => "event-{$league->event_id}-{$league->mtgo_course_id}",

            // No stable identity yet. Stay local-only rather than mint a key that
            // would change when the course id arrives; Deck::scopeSyncableIdentity
            // keeps this shape out of sync entirely.
            default => "league-{$league->id}",
        };
    }
}
