<?php

namespace App\Data\Front;

use App\Models\ArchetypeDeck;
use Carbon\Carbon;
use Spatie\LaravelData\Data;

/** @typescript */
class ArchetypeDeckData extends Data
{
    public function __construct(
        public int $id,
        public string $uuid,
        public int $seenCount,
        public ?Carbon $lastSyncedAt,
        /** @var CardData[] */
        public array $cards,
        public ?int $facingWinrate,
        public int $wins,
        public int $losses,
    ) {}

    /**
     * @param  array{winrate:int, wins:int, losses:int}|null  $stats
     */
    public static function fromModel(ArchetypeDeck $deck, ?array $stats = null): self
    {
        $cards = $deck->cards->map(function ($card) {
            $cardData = CardData::fromModel($card);
            $cardData->quantity = $card->pivot->quantity;
            $cardData->sideboard = (bool) $card->pivot->sideboard;

            return $cardData;
        })->all();

        return new self(
            id: $deck->id,
            uuid: $deck->uuid,
            seenCount: $deck->seen_count,
            lastSyncedAt: $deck->last_synced_at,
            cards: $cards,
            facingWinrate: $stats['winrate'] ?? null,
            wins: $stats['wins'] ?? 0,
            losses: $stats['losses'] ?? 0,
        );
    }
}
