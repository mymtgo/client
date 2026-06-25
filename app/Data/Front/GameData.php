<?php

namespace App\Data\Front;

use App\Models\Game;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Lazy;

/** @typescript  */
class GameData extends Data
{
    public function __construct(
        public int $id,
        public ?bool $localOnPlay,
        public int $localMulligans,
        public int $opponentMulligans,
        public ?int $localDice,
        public ?int $opponentDice,
        public Lazy $timeline,
        public Lazy|array $localDeck,
        public Lazy|array $opponentDeck,
    ) {}

    public static function fromModel(Game $game): self
    {
        return new self(
            id: $game->id,
            localOnPlay: $game->local_on_play,
            localMulligans: $game->local_mulligans ?? 0,
            opponentMulligans: $game->opp_mulligans ?? 0,
            localDice: $game->local_dice,
            opponentDice: $game->opp_dice,
            timeline: Lazy::whenLoaded('timeline', $game, fn () => GameTimelineData::collect($game->timeline)),
            localDeck: Lazy::whenLoaded('decks', $game, fn () => $game->localDeck()?->deck_json ?? []),
            opponentDeck: Lazy::whenLoaded('decks', $game, fn () => $game->opponentDeck()?->deck_json ?? []),
        );
    }
}
