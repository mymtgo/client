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
        /** @var Lazy|list<GameResultSummaryData> */
        public Lazy|array $gameResults,
        /**
         * The colours the opponent was seen casting, WUBRG-ordered.
         *
         * Only filled where a caller has resolved it in bulk (see
         * ResolveOpponentColorIdentities) — deriving it per match would mean a
         * card lookup per row. Limited is the case that needs it: a draft seat
         * has no archetype, so its colours are all a reader gets.
         */
        public ?string $opponentColors = null,
    ) {}

    public static function fromModel(MtgoMatch $match): self
    {
        return new self(
            id: $match->id,
            format: MtgoMatch::displayFormat($match->format),
            matchType: $match->match_type,
            leagueGame: $match->league_id !== null,
            gamesWon: $match->gamesWon(),
            gamesLost: $match->gamesLost(),
            result: $match->isWin() ? 'won' : 'lost',
            startedAt: $match->started_at,
            since: $match->started_at->toLocal()->diffForHumans(),
            startedAtFormatted: $match->started_at->toLocal()->format('d/m/Y g:ia'),
            matchTime: $match->matchTime,
            notes: $match->notes,
            deck: Lazy::whenLoaded('deck', $match, fn () => DeckData::from($match->deck)),
            opponentArchetypes: Lazy::whenLoaded('opponentArchetypes', $match, fn () => MatchArchetypeData::collect($match->opponentArchetypes)),
            opponentName: Lazy::whenLoaded('games', $match, fn () => $match->games->first()?->players->first(fn ($p) => ! $p->pivot->is_local)?->username),
            leagueName: Lazy::whenLoaded('league', $match, fn () => $match->league?->name),
            games: Lazy::create(fn () => GameData::collect($match->games)),
            gameResults: Lazy::whenLoaded('games', $match, fn () => $match->games
                ->filter(fn ($g) => $g->won !== null)
                ->sortBy('started_at')
                ->values()
                ->map(fn ($g) => new GameResultSummaryData(
                    result: $g->won ? 'W' : 'L',
                    onPlay: $g->players->first(fn ($p) => $p->pivot->is_local)?->pivot->on_play,
                ))->all()),
        );
    }
}
