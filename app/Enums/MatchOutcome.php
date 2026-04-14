<?php

namespace App\Enums;

enum MatchOutcome: string
{
    case Win = 'win';
    case Loss = 'loss';
    case Draw = 'draw';
    case Unknown = 'unknown';
}
