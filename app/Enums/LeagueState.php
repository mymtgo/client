<?php

namespace App\Enums;

enum LeagueState: string
{
    case Active = 'active';
    case Complete = 'complete';
    case Partial = 'partial';
}
