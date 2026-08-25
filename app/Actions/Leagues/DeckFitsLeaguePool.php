<?php

namespace App\Actions\Leagues;

use App\Models\Card;
use App\Models\League;

class DeckFitsLeaguePool
{
    /**
     * Fraction of non-basic main-deck cards that must come from the pool.
     * A 40-card limited deck carries roughly 23 spells; 0.75 tolerates a
     * couple of unresolved or oddly printed cards without letting a
     * different draft's deck through.
     */
    private const MIN_COVERAGE = 0.75;

    /**
     * @param  array<int, int>  $mainDeck  catalog_id => quantity, main deck only
     */
    public static function run(League $league, array $mainDeck): bool
    {
        $draft = $league->draft;
        if (! $draft || $draft->picks()->whereNotNull('picked_catalog_id')->doesntExist()) {
            return true;
        }

        $catalogIds = array_map('strval', array_keys($mainDeck));

        $basics = Card::query()
            ->whereIn('mtgo_id', $catalogIds)
            ->where('type', 'Basic Land')
            ->pluck('mtgo_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        // Cards MTGO's catalog hasn't resolved yet (async enrichment) are
        // neutral: they can't be checked either way, so they're dropped from
        // both sides of the ratio rather than counted as uncovered.
        $knownTypes = Card::query()
            ->whereIn('mtgo_id', $catalogIds)
            ->whereNotNull('type')
            ->pluck('mtgo_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $pool = $draft->poolCounts();
        $needed = 0;
        $covered = 0;

        foreach ($mainDeck as $catalogId => $quantity) {
            $catalogId = (int) $catalogId;

            if (in_array($catalogId, $basics, true) || ! in_array($catalogId, $knownTypes, true)) {
                continue;
            }

            $needed += $quantity;
            $covered += min($quantity, $pool[$catalogId] ?? 0);
        }

        if ($needed === 0) {
            return true;
        }

        return $covered / $needed >= self::MIN_COVERAGE;
    }
}
