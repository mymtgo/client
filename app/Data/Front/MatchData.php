<?php

namespace App\Data\Front;

use App\Models\MtgoMatch;
use Carbon\Carbon;
use Illuminate\Support\Collection;
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
        public string $since,
        public string $startedAtFormatted,
        public ?string $matchTime,
        public ?string $notes,
        public Lazy|DeckData $deck,
        public Lazy $opponentArchetypes,
        public Lazy|string|null $opponentName,
        public Lazy|string|null $leagueName,
        public Lazy|Collection $games,
        /** @var array<int, array{result: string, onPlay: bool|null}> */
        public Lazy|array $gameResults,
    ) {}

    public static function fromModel(MtgoMatch $match): self
    {
        return new self(
            id: $match->id,
            format: MtgoMatch::displayFormat($match->format),
            matchType: $match->match_type,
            leagueGame: $match->league_id !== null && ! ($match->league->phantom ?? true),
            gamesWon: $match->gamesWon(),
            gamesLost: $match->gamesLost(),
            result: $match->isWin() ? 'won' : 'lost',
            startedAt: $match->started_at,
            since: $match->started_at->diffForHumans(),
            startedAtFormatted: $match->started_at->format('d/m/Y g:ia'),
            matchTime: $match->matchTime,
            notes: $match->notes,
            deck: Lazy::whenLoaded('deck', $match, fn () => DeckData::from($match->deck)),
            opponentArchetypes: Lazy::whenLoaded('opponentArchetypes', $match, fn () => MatchArchetypeData::collect($match->opponentArchetypes)),
            opponentName: Lazy::whenLoaded('games', $match, fn () => $match->games->first()?->players->first(fn ($p) => ! $p->pivot->is_local)?->username),
            leagueName: Lazy::whenLoaded('league', $match, fn () => $match->league?->name),
            games: Lazy::whenLoaded('games', $match, fn () => GameData::collect($match->games)),
            gameResults: Lazy::whenLoaded('games', $match, fn () => $match->games
                ->filter(fn ($g) => $g->won !== null)
                ->sortBy('started_at')
                ->values()
                ->map(fn ($g) => [
                    'result' => $g->won ? 'W' : 'L',
                    'onPlay' => $g->players->first(fn ($p) => $p->pivot->is_local)?->pivot->on_play,
                ])->all()),
        );
    }
}
