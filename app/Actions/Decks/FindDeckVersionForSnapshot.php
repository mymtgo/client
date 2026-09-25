<?php

namespace App\Actions\Decks;

use App\Models\Deck;
use App\Models\DeckVersion;
use App\Sidecar\DeckSnapshot;

class FindDeckVersionForSnapshot
{
    /**
     * The deck is the one whose MTGO NetDeckId the sidecar reported; the
     * version is found by signature within that deck only, which removes the
     * cross-deck ambiguity the log path has. The oracle fallback covers
     * printing drift and is kept to the same deck.
     */
    public static function run(DeckSnapshot $snapshot, ?int $accountId): ?DeckVersion
    {
        if ($snapshot->netDeckId === null || $snapshot->items === null) {
            return null;
        }

        $deck = Deck::query()
            ->where('mtgo_id', (string) $snapshot->netDeckId)
            ->when($accountId, fn ($query) => $query->where('account_id', $accountId))
            ->first();

        if ($deck === null) {
            return null;
        }

        $version = $deck->versions()->where('signature', GenerateDeckSignature::run($snapshot->items))->first();

        if ($version !== null) {
            return $version;
        }

        $byOracle = FindDeckVersionByOracleIdentity::run($snapshot->items, $accountId);

        return $byOracle?->deck_id === $deck->id ? $byOracle : null;
    }
}
