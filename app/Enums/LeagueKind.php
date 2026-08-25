<?php

namespace App\Enums;

enum LeagueKind: string
{
    case Constructed = 'constructed';
    case Draft = 'draft';
    case Sealed = 'sealed';

    public function isLimited(): bool
    {
        return $this !== self::Constructed;
    }
}
