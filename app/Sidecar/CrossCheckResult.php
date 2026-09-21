<?php

namespace App\Sidecar;

final readonly class CrossCheckResult
{
    /** @param array<int, string> $failures */
    public function __construct(
        public bool $passed,
        public int $compared,
        public array $failures,
    ) {}
}
