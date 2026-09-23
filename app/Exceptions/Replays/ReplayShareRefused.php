<?php

namespace App\Exceptions\Replays;

use RuntimeException;

/** The server, or the client's own checks, declined to share a replay. */
class ReplayShareRefused extends RuntimeException
{
    public const SUPPORTER = 'supporter';

    public const NOT_CLAIMED = 'not_claimed';

    public const TOO_LARGE = 'too_large';

    public const INVALID = 'invalid';

    public const ACCOUNT_UNKNOWN = 'account_unknown';

    public const NAME_SURVIVED = 'name_survived';

    public function __construct(public readonly string $reason)
    {
        parent::__construct("Replay share refused: {$reason}");
    }
}
