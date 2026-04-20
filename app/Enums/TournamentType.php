<?php

namespace App\Enums;

enum TournamentType: string
{
    case Constructed = 'Constructed';
    case Limited = 'Limited';

    /**
     * Derive the tournament type from MTGO's PlayFormatCd.
     * Constructed formats start with 'C' (CMODERN, CSTANDARD, etc.).
     * Limited formats start with 'S' (S6ECL, etc.) and other codes.
     */
    public static function fromPlayFormatCd(?string $playFormatCd): ?self
    {
        if (! $playFormatCd) {
            return null;
        }

        $prefix = strtoupper(substr(trim($playFormatCd), 0, 1));

        return match ($prefix) {
            'C' => self::Constructed,
            'S' => self::Limited,
            default => null,
        };
    }
}
