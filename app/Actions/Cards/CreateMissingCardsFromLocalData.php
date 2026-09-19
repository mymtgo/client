<?php

declare(strict_types=1);

namespace App\Actions\Cards;

use App\Actions\Decks\GenerateDeckSignature;
use App\Models\Card;
use App\Models\DeckVersion;
use Illuminate\Support\Facades\DB;

/**
 * Creates card stubs for every MTGO catalog id the local database already
 * references but holds no `cards` row for, then leans on
 * {@see CreateMissingCards} to queue the Scryfall fill.
 *
 * Log ingestion creates its stubs as it parses, so a device that built its
 * history from local logs never needs this: GenerateDeckSignature and
 * CreateGames both call CreateMissingCards on the way past. A device that
 * received its history from cloud sync does. Bundles are written straight
 * to the tables inside Model::withoutEvents(), signatures arrive already
 * built, and nothing on that path ever reaches CreateMissingCards, so the
 * cards table stays empty: no deck covers, no card names, no images.
 *
 * Three sources cover everything a bundle can carry. Deck version
 * signatures hold the decklists, game_player.deck_json holds the lists as
 * actually registered per game, and timelines hold tokens and other
 * permanents that never appear in a decklist at all.
 */
class CreateMissingCardsFromLocalData
{
    /**
     * SQLite caps how many bindings one statement may carry, and a busy
     * database can reference tens of thousands of distinct catalog ids, so
     * the ids go down in chunks rather than as one whereIn.
     */
    private const CHUNK = 500;

    /**
     * @return int the number of stubs created
     */
    public static function run(): int
    {
        $before = Card::query()->count();

        $ids = collect(self::fromDeckSignatures())
            ->merge(self::fromGamePlayerDecks())
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        foreach ($ids->chunk(self::CHUNK) as $chunk) {
            CreateMissingCards::run($chunk->all());
        }

        // Tokens and other permanents only ever appear in game state, so
        // they need the timeline scan even when every decklist resolved.
        CreateMissingCardsFromTimelines::run();

        return Card::query()->count() - $before;
    }

    /**
     * A signature is base64 of `catalogId:quantity:sideboard` joined by
     * pipes, exactly as {@see GenerateDeckSignature} writes it. Decoding
     * beats re-deriving: the signature is the only decklist a synced deck
     * version carries.
     *
     * Every id is checked rather than cast, because base64 of anything
     * decodes to something. A non-numeric first segment means the string
     * is not a signature, and casting it would invent catalog ids.
     *
     * @return list<int>
     */
    private static function fromDeckSignatures(): array
    {
        $ids = [];

        DeckVersion::query()
            ->whereNotNull('signature')
            ->orderBy('id')
            ->lazyById(self::CHUNK)
            ->each(function (DeckVersion $version) use (&$ids): void {
                $decoded = base64_decode((string) $version->signature, true);

                if ($decoded === false || $decoded === '') {
                    return;
                }

                foreach (explode('|', $decoded) as $entry) {
                    $catalogId = explode(':', $entry)[0];

                    // A catalog id is always digits. Anything else means
                    // this is not a decklist signature, and coercing it
                    // would invent a card: (int) '3f2a-...' is 3, which
                    // would then be fetched and stored as a real row.
                    if ($catalogId !== '' && ctype_digit($catalogId)) {
                        $ids[] = (int) $catalogId;
                    }
                }
            });

        return $ids;
    }

    /**
     * @return list<int>
     */
    private static function fromGamePlayerDecks(): array
    {
        $ids = [];

        // GamePlayer is a Pivot with no $table and a non-incrementing key,
        // so GamePlayer::query() resolves to `game_players` and cannot be
        // walked by id. The query builder is the accurate tool here.
        DB::table('game_player')
            ->whereNotNull('deck_json')
            ->orderBy('id')
            ->lazyById(self::CHUNK)
            ->each(function (object $row) use (&$ids): void {
                $cards = json_decode((string) $row->deck_json, true);

                if (! is_array($cards)) {
                    return;
                }

                foreach ($cards as $card) {
                    if (is_array($card) && isset($card['mtgo_id'])) {
                        $ids[] = (int) $card['mtgo_id'];
                    }
                }
            });

        return $ids;
    }
}
