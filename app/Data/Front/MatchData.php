<?php

namespace App\Data\Front;

use App\Models\MtgoMatch;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
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
        public Lazy|string|null $opponentName,
        public Lazy|Collection $games,
    ) {}

    public static function fromModel(MtgoMatch $match): self
    {
        return new self(
            id: $match->id,
            format: Str::title(strtolower(substr($match->format, 1))),
            matchType: $match->match_type,
            leagueGame: (bool) ! $match->league->phantom,
            gamesWon: $match->games_won,
            gamesLost: $match->games_lost,
            result: $match->games_won > $match->games_lost ? 'won' : 'lost',
            startedAt: $match->started_at,
            matchTime: $match->matchTime,
            deck: Lazy::whenLoaded('deck', $match, fn () => DeckData::from($match->deck)),
            opponentArchetypes: Lazy::whenLoaded('opponentArchetypes', $match, fn () => MatchArchetypeData::collect($match->opponentArchetypes)),
            opponentName: Lazy::whenLoaded('opponentArchetypes', $match, fn () => $match->opponentArchetypes->first()?->player?->username),
            games: Lazy::whenLoaded('games', $match, fn () => GameData::collect($match->games)),
        );
    }
}
