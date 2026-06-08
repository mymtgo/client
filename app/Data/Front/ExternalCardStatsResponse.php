<?php

namespace App\Data\Front;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

/** @typescript */
final class ExternalCardStatsResponse extends Data
{
    public function __construct(
        /** @var array<int, array<string, mixed>> */
        public array $stats,
        public DeckWinrateData $archetypeWinrate,
        /** @var DataCollection<int, ExternalOpponentData> */
        public DataCollection $opponents,
        public ?string $refreshedAt,
    ) {}

    public static function createEmpty(): self
    {
        return new self(
            stats: [],
            archetypeWinrate: new DeckWinrateData(wins: 0, games: 0, rate: 0.5),
            opponents: ExternalOpponentData::collect([], DataCollection::class),
            refreshedAt: null,
        );
    }
}
