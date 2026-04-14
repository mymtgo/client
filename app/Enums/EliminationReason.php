<?php

namespace App\Enums;

enum EliminationReason: string
{
    case MatchLoss = 'match_loss';
    case Drop = 'drop';

    public static function fromMtgoReason(string $reason): ?self
    {
        return match ($reason) {
            'Match Loss' => self::MatchLoss,
            'Drop' => self::Drop,
            default => null,
        };
    }
}
