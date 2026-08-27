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

    /**
     * How many matches make a full MTGO league run for this kind. Draft
     * leagues are three rounds per draft; constructed and sealed run five.
     */
    public function roundCount(): int
    {
        return match ($this) {
            self::Draft => 3,
            self::Constructed, self::Sealed => 5,
        };
    }
}
