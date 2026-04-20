<?php

namespace App\Data\Front;

use App\Models\Deck;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Spatie\LaravelData\Data;

/** @typescript  */
class DeckGroupStatsData extends Data
{
    public function __construct(
        public int $totalMatches,
        public int $totalWins,
        public ?float $winrate,
        public ?Carbon $lastPlayedAt,
    ) {}

    /**
     * @param  Collection<int, Deck>  $decks
     */
    public static function fromDecks(Collection $decks): self
    {
        $totalMatches = (int) $decks->sum('matches_count');
        $totalWins = (int) $decks->sum('won_matches_count');

        $winrate = $totalMatches > 0
            ? round(($totalWins / $totalMatches) * 100, 1)
            : null;

        $lastPlayedRaw = $decks->max('matches_max_started_at');

        return new self(
            totalMatches: $totalMatches,
            totalWins: $totalWins,
            winrate: $winrate,
            lastPlayedAt: $lastPlayedRaw ? Carbon::parse($lastPlayedRaw) : null,
        );
    }
}
