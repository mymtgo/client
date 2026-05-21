<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

class LogInstanceSealed
{
    use Dispatchable;

    public function __construct(
        public readonly int $instanceId,
        public readonly string $reason,
    ) {}
}
