<?php

namespace App\Enums;

/**
 * How a match's outcome was determined — `manual` is user-set in the
 * needs-attention UI and baked into `{match}.json` so server re-derivation
 * preserves it. See docs/v1/contract/spec.md (`match.outcome_source`).
 */
enum OutcomeSource: string
{
    case Resolved = 'resolved';
    case Manual = 'manual';
    case Unknown = 'unknown';
}
