<?php

namespace App\Data\ProjectedMatch;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;

class GameData extends Data
{
    /**
     * @param  array<int, CardStatData>  $card_stats
     * @param  array<int, TimelineEntryData>  $timeline
     */
    public function __construct(
        public ?int $mtgo_id,
        public ?bool $won,
        public ?string $started_at,
        public ?string $ended_at,
        public ?int $turn_count,
        public ?bool $local_on_play,
        public ?int $local_mulligans,
        public ?int $opp_mulligans,
        public ?int $local_dice,
        public ?int $opp_dice,
        public ?int $local_instance,
        public ?int $opp_instance,
        public ?GameDeckData $local_deck,
        public ?GameDeckData $opponent_deck,
        #[DataCollectionOf(CardStatData::class)]
        public array $card_stats,
        #[DataCollectionOf(TimelineEntryData::class)]
        public array $timeline,
    ) {}
}
