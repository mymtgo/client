<?php

namespace App\Enums;

/**
 * Backing values are the `{match}.json` contract strings — see
 * docs/v1/contract/spec.md (`match.state`).
 */
enum MatchState: string
{
    case Started = 'Started';
    case InProgress = 'InProgress';
    case Ended = 'Ended';
    case Complete = 'Complete';
    case Abandoned = 'Abandoned';
}
