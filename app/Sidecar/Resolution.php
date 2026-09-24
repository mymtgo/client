<?php

namespace App\Sidecar;

final readonly class Resolution
{
    public function __construct(
        public mixed $value,
        public string $chosenSource,
        public bool $disagree,
    ) {}
}
