<?php

declare(strict_types=1);

namespace App\Exceptions\Sync;

use Exception;

/**
 * The server refused to enable a deck: every slot on the account's tier is
 * taken. $freesAt is the ISO-8601 instant the earliest cooling slot
 * releases, or null when every slot is held by an enabled deck.
 */
class SlotLimitException extends Exception
{
    public function __construct(
        public readonly int $limit,
        public readonly int $used,
        public readonly ?string $freesAt,
    ) {
        parent::__construct('No free deck sync slot.');
    }
}
