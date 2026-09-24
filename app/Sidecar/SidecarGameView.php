<?php

namespace App\Sidecar;

use Carbon\CarbonImmutable;

final readonly class SidecarGameView
{
    /**
     * @param  array<int, string>  $playerNames  slot => name
     * @param  array<string, array{start: ?int, end: ?int, min: ?int, sideboard_used: ?int}>  $clockByName
     * @param  array<int, array<string, mixed>>  $keyframes
     */
    public function __construct(
        public string $gameMtgoId,
        public int $gameNumber,
        public array $playerNames,
        public ?CarbonImmutable $startedAt,
        public ?CarbonImmutable $endedAt,
        public ?string $winnerName,
        public ?string $onPlayName,
        public bool $hasStart,
        public bool $hasEnd,
        public bool $allVerified,
        public int $turnCount,
        public array $clockByName,
        public array $keyframes,
    ) {}

    public function coverageComplete(): bool
    {
        return $this->hasStart && $this->hasEnd;
    }
}
