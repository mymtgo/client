<?php

namespace App\Actions\Limited;

use App\Actions\Decks\GenerateDeckSignature;
use App\Models\LimitedDeckSnapshot;
use App\Models\MtgoMatch;

class RecordRegisteredDeckSnapshot
{
    /**
     * The deck MTGO handed back when the match opened (FlsMatchDeckGetResp).
     * One row per league + match; re-running updates in place.
     */
    public static function run(MtgoMatch $match): ?LimitedDeckSnapshot
    {
        if (! $match->league_id) {
            return null;
        }

        $event = ReadRegisteredDeck::sourceEvent($match);

        if (! $event) {
            return null;
        }

        $cards = ReadRegisteredDeck::fromEvent($event);

        if ($cards === null) {
            return null;
        }

        $signature = GenerateDeckSignature::run(collect($cards)->map(fn (array $card) => [
            'mtgo_id' => $card['catalog_id'],
            'quantity' => $card['quantity'],
            'sideboard' => $card['sideboard'] ? 'true' : 'false',
        ]));

        return LimitedDeckSnapshot::updateOrCreate(
            ['league_id' => $match->league_id, 'match_id' => $match->id, 'source' => 'registered'],
            ['cards' => $cards, 'signature' => $signature, 'captured_at' => $event->logged_at],
        );
    }
}
