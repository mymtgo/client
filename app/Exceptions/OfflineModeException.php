<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a request to the community API is attempted while offline mode
 * is enabled. This is a user choice, not a fault: callers should degrade
 * quietly and must never present it as a network failure.
 */
class OfflineModeException extends RuntimeException
{
    public function __construct(string $message = 'Offline mode is enabled.')
    {
        parent::__construct($message);
    }
}
