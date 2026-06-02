<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

final class ExternalCardStatsUnavailable extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly int $status = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf('External card stats unavailable (reason=%s, status=%d)', $reason, $status),
            0,
            $previous,
        );
    }
}
