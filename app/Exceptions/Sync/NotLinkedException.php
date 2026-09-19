<?php

declare(strict_types=1);

namespace App\Exceptions\Sync;

use RuntimeException;

/**
 * Thrown when sync credentials are missing, or when the server has revoked
 * the device's refresh token. Callers should fall back to prompting the
 * user to relink the device rather than treating this as a transient fault.
 */
class NotLinkedException extends RuntimeException
{
    public function __construct(string $message = 'This device is not linked to a MyMTGO account.')
    {
        parent::__construct($message);
    }
}
