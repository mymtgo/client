<?php

namespace App\Sidecar;

final readonly class MatchSnapshot
{
    public function __construct(
        public string $phase,
        public ?DeckSnapshot $registeredDeck,
        public ?LeagueSnapshot $league,
        public bool $isNew = false,
    ) {}
}
