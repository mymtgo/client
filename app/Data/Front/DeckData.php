<?php

namespace App\Data\Front;

use App\Models\Deck;
use Illuminate\Support\Str;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Lazy;

/** @typescript  */
class DeckData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public string $format,
        public int $matchesCount,
        public int $matchesWon,
        public int $matchesLost,
        public int $winrate,
        public Lazy $matches,
        public Lazy $identity,
        public Lazy $cards,
    ) {}

    public static function fromModel(Deck $deck): self
    {
        $winrate = 0;

        if ($deck->won_matches_count) {
            $winrate = $deck->won_matches_count / $deck->matches_count;
        }

        return new self(
            id: $deck->id,
            name: $deck->name,
            format: Str::title(strtolower(substr($deck->format, 1))),
            matchesCount: $deck->matches_count ?: 0,
            matchesWon: $deck->won_matches_count ?: 0,
            matchesLost: $deck->lost_matches_count ?: 0,
            winrate: round($winrate * 100),
            matches: Lazy::whenLoaded('matches', $deck, fn () => MatchData::collect($deck->matches)),
            identity: Lazy::whenLoaded('cards', $deck, function () use ($deck) {
                return $deck->cards->pluck('color_identity')->map(
                    fn ($identity) => explode(',', $identity)
                )->flatten()->filter()->unique()->values()->join(',');
            }),
            cards: Lazy::whenLoaded('cards', $deck, fn () => CardData::collect($deck->cards)),
        );
    }
}
