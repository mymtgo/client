<?php

namespace App\Data\Front;

use App\Models\MtgoMatch;
use Carbon\Carbon;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Lazy;

/** @typescript  */
class MatchData extends Data
{
    public function __construct(
        public int $id,
        public string $format,
        public string $matchType,
        public bool $leagueGame,
        public int $gamesWon,
        public int $gamesLost,
        public string $result,
        public Carbon $startedAt,
        public string $matchTime,
        public Lazy|DeckData $deck,
        public Lazy $opponentArchetypes,
    ) {}

    public static function fromModel(MtgoMatch $match): self
    {
        return new self(
            id: $match->id,
            format: $match->format,
            matchType: $match->match_type,
            leagueGame: (bool) $match->league_id,
            gamesWon: $match->games_won,
            gamesLost: $match->games_lost,
            result: $match->games_won > $match->games_lost ? 'won' : 'lost',
            startedAt: $match->started_at,
            matchTime: $match->matchTime,
            deck: Lazy::whenLoaded('deck', $match, fn () => DeckData::from($match->deck)),
            opponentArchetypes: Lazy::whenLoaded('opponentArchetypes', $match, fn () => MatchArchetypeData::collect($match->opponentArchetypes)),
        );
    }
}
