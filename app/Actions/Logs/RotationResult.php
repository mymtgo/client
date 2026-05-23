<?php

namespace App\Actions\Logs;

final class RotationResult
{
    public function __construct(
        public readonly bool $rotated,
        public readonly ?string $reason,
    ) {}
}
