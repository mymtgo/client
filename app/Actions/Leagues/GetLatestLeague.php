<?php

namespace App\Actions\Leagues;

use App\Models\Deck;
use App\Models\League;
use Illuminate\Support\Collection;

class GetLatestLeague
{
    /**
     * Get the most recent complete, non-phantom league for a deck.
     *
     * @param  Collection  $matchIds  Match IDs belonging to this deck
     * @return array|null Formatted league run or null
     */
    public static function run(Deck $deck, Collection $matchIds): ?array
    {
        $league = League::whereHas('matches', fn ($q) => $q->whereIn('matches.id', $matchIds))
            ->where('state', 'complete')
            ->with('deckVersion.deck.cover')
            ->orderByDesc('started_at')
            ->first();

        if (! $league) {
            return null;
        }

        $formatted = FormatLeagueRuns::run(collect([$league]));

        return $formatted[0] ?? null;
    }
}
