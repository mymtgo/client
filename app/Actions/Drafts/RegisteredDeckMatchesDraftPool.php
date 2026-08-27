<?php

namespace App\Actions\Drafts;

use App\Actions\Leagues\DeckFitsLeaguePool;
use App\Enums\LeagueKind;
use App\Models\Draft;
use App\Models\League;

class RegisteredDeckMatchesDraftPool
{
    /**
     * Minimum quantity-weighted overlap between a candidate league's
     * registered main deck and the draft's pool before a match is even
     * considered. A 40-card deck built from the pool overlaps almost
     * entirely; a handful of shared catalog ids is coincidence.
     */
    public const MIN_POOL_OVERLAP = 15;

    /**
     * Positive evidence that the league's registered deck was built from this
     * draft's pool.
     *
     * A freshly ingested pool's Card rows are often bare stubs with no type
     * yet (CreateMissingCards runs in the same tick as ProcessDraftEvents),
     * and DeckFitsLeaguePool treats an unresolved catalog id as neutral,
     * dropped from the coverage ratio, so on its own it would wave through
     * any candidate on zero real overlap. The raw quantity-weighted overlap
     * gate is what makes this evidence rather than an absence of objection.
     */
    public static function run(Draft $draft, League $league): bool
    {
        $snapshot = $league->deckSnapshots->where('source', 'registered')->last();

        if (! $snapshot) {
            return false;
        }

        $main = [];
        foreach ($snapshot->cards as $card) {
            if (! $card['sideboard']) {
                $main[$card['catalog_id']] = ($main[$card['catalog_id']] ?? 0) + $card['quantity'];
            }
        }

        $pool = $draft->poolCounts();
        $overlap = 0;

        foreach ($main as $catalogId => $quantity) {
            $overlap += min($quantity, $pool[$catalogId] ?? 0);
        }

        if ($overlap < self::MIN_POOL_OVERLAP) {
            return false;
        }

        /** DeckFitsLeaguePool reads the pool off the league's draft, so probe with this one. */
        $probe = new League(['kind' => LeagueKind::Draft]);
        $probe->setRelation('draft', $draft);

        return DeckFitsLeaguePool::run($probe, $main);
    }
}
