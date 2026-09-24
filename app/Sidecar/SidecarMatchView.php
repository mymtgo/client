<?php

namespace App\Sidecar;

final readonly class SidecarMatchView
{
    /**
     * @param  array<int, string>  $playerNames
     * @param  ?array{winner: ?string, score: array{int, int}}  $matchResult
     * @param  array<int, SidecarGameView>  $games  keyed by game mtgo id (PHP coerces the numeric-string key to int)
     */
    public function __construct(
        public string $matchMtgoId,
        public array $playerNames,
        public ?array $matchResult,
        public bool $matchEnded,
        public bool $allVerified,
        public ?string $latestProbeUsername,
        public array $games,
    ) {}
}
