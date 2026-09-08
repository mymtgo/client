<?php

namespace App\Data\Front;

use App\Models\Deck;
use App\Support\MatchRecord;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Spatie\LaravelData\Data;

/** @typescript  */
class DeckGroupStatsData extends Data
{
    public function __construct(
        public MatchRecordData $record,
        public ?Carbon $lastPlayedAt,
    ) {}

    /**
     * @param  Collection<int, Deck>  $decks
     */
    public static function fromDecks(Collection $decks): self
    {
        $record = $decks->reduce(
            fn (MatchRecord $carry, Deck $deck) => $carry->add(MatchRecord::fromTotal(
                wins: (int) ($deck->won_matches_count ?? 0),
                losses: (int) ($deck->lost_matches_count ?? 0),
                total: (int) ($deck->matches_count ?? 0),
            )),
            MatchRecord::empty(),
        );

        $lastPlayedRaw = $decks->max('matches_max_started_at');

        return new self(
            record: $record->toData(),
            lastPlayedAt: $lastPlayedRaw ? Carbon::parse($lastPlayedRaw) : null,
        );
    }
}
