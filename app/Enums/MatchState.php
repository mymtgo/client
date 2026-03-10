<?php

namespace App\Enums;

enum MatchState: string
{
    case Started = 'started';
    case InProgress = 'in_progress';
    case Ended = 'ended';
    case Complete = 'complete';
    case Voided = 'voided';
}
