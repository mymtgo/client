<?php

namespace App\Actions\Import;

use App\Models\Card;
use App\Models\DeckVersion;

class ScoreMatchConfidence
{
    /**
     * Compute how well a set of mtgo_ids matches a deck version's card list.
     *
     * @param  array<int>  $mtgoIds  Card mtgo_ids extracted from game log
     * @return float|null Confidence 0.0-1.0, or null if no oracle_ids resolved
     */
    public static function run(array $mtgoIds, DeckVersion $deckVersion): ?float
    {
        if (empty($mtgoIds)) {
            return null;
        }

        $oracleIds = Card::whereIn('mtgo_id', $mtgoIds)
            ->whereNotNull('oracle_id')
            ->pluck('oracle_id')
            ->unique()
            ->values();

        if ($oracleIds->isEmpty()) {
            return null;
        }

        $deckOracleIds = collect($deckVersion->cards)->pluck('oracle_id')->unique()->values();

        if ($deckOracleIds->isEmpty()) {
            return null;
        }

        $overlap = $oracleIds->intersect($deckOracleIds)->count();

        return round($overlap / $oracleIds->count(), 2);
    }
}
