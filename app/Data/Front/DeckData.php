<?php

namespace App\Data\Front;

use App\Models\Deck;
use App\Models\MtgoMatch;
use Carbon\Carbon;
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
        public ?string $colorIdentity,
        public ?Carbon $lastPlayedAt,
        public ?string $lastPlayedAtHuman,
        public Lazy $matches,
        public Lazy $identity,
        public Lazy $cards,
    ) {}

    public static function fromModel(Deck $deck): self
    {
        $winrate = 0;

        if ($deck->matches_count > 0) {
            $winrate = $deck->won_matches_count / $deck->matches_count;
        }

        return new self(
            id: $deck->id,
            name: $deck->name,
            format: MtgoMatch::displayFormat($deck->format),
            matchesCount: $deck->matches_count ?: 0,
            matchesWon: $deck->won_matches_count ?: 0,
            matchesLost: $deck->lost_matches_count ?: 0,
            winrate: (int) round($winrate * 100),
            colorIdentity: $deck->color_identity,
            lastPlayedAt: $deck->matches_max_started_at ? Carbon::parse($deck->matches_max_started_at) : null,
            lastPlayedAtHuman: $deck->matches_max_started_at ? Carbon::parse($deck->matches_max_started_at)->diffForHumans() : null,
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
