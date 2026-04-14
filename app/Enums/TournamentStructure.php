<?php

namespace App\Enums;

enum TournamentStructure: string
{
    case Swiss = 'swiss';

    public static function fromMtgoCode(string $code): ?self
    {
        return match (strtoupper($code)) {
            'SWISS' => self::Swiss,
            default => null,
        };
    }
}
