<?php

namespace App\Enums;

enum MatchState: string
{
    case Started = 'started';
    case InProgress = 'in_progress';
    case Ended = 'ended';
    case PendingResult = 'pending_result';
    case Complete = 'complete';
    case Voided = 'voided';
}
