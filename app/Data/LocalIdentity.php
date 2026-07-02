<?php

namespace App\Data;

use Spatie\LaravelData\Data;

/**
 * The resolved local MTGO identity — only ever produced when the logged-in
 * MTGO username matches the bound cloud account (the mismatch guard).
 */
class LocalIdentity extends Data
{
    public function __construct(
        public ?int $mtgoPlayerId,
        public string $mtgoUsername,
    ) {}
}
