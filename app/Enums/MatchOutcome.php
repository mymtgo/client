<?php

namespace App\Enums;

/**
 * Backing values are the `{match}.json` contract strings — see
 * docs/v1/contract/spec.md (`match.outcome`).
 */
enum MatchOutcome: string
{
    case Win = 'Win';
    case Loss = 'Loss';
    case Draw = 'Draw';
    case Unknown = 'Unknown';
}
