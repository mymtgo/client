<?php

namespace App\Data\ProjectedMatch;

use App\Enums\MatchOutcome;
use App\Enums\OutcomeSource;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;

class MatchData extends Data
{
    /**
     * @param  array<int, GameData>  $games
     */
    public function __construct(
        public string $token,
        public ?int $mtgo_id,
        public ?string $format,
        public ?string $match_type,
        public MatchOutcome $outcome,
        public OutcomeSource $outcome_source,
        public ?string $state,
        public ?string $started_at,
        public ?string $ended_at,
        public ?string $notes,
        public OpponentData $opponent,
        public ?DeckData $deck,
        public ?LeagueData $league,
        public ?TournamentData $tournament,
        #[DataCollectionOf(GameData::class)]
        public array $games,
        public ?OpponentArchetypeData $opponent_archetype,
    ) {}
}
