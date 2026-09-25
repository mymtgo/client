<?php

namespace App\Sidecar;

use App\Enums\LeagueState;

final readonly class LeagueRunDecision
{
    /**
     * @param  array<int, LeagueState>  $close  league id => state to close it with
     */
    public function __construct(
        public ?int $targetLeagueId,
        public bool $mint,
        public bool $reactivate,
        public array $close,
    ) {}

    public static function undecided(): self
    {
        return new self(null, false, false, []);
    }

    public function isUndecided(): bool
    {
        return $this->targetLeagueId === null && ! $this->mint;
    }
}
