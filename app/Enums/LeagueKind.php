<?php

namespace App\Enums;

use App\Models\MtgoMatch;

enum LeagueKind: string
{
    case Constructed = 'constructed';
    case Draft = 'draft';
    case Sealed = 'sealed';

    /**
     * The kind an MTGO format code belongs to: S6FRA is sealed, DHOB is
     * draft, anything else (CMODERN, unknown) is constructed.
     */
    public static function fromFormatCode(?string $format): self
    {
        if (! MtgoMatch::isLimitedFormatCode($format)) {
            return self::Constructed;
        }

        return str_starts_with((string) $format, 'S') ? self::Sealed : self::Draft;
    }

    public function isLimited(): bool
    {
        return $this !== self::Constructed;
    }

    /**
     * How many matches make a full MTGO league run for this kind. Draft
     * leagues are three rounds per draft, constructed five, and sealed
     * "play up to 6 matches" per the league description.
     */
    public function roundCount(): int
    {
        return match ($this) {
            self::Draft => 3,
            self::Constructed => 5,
            self::Sealed => 6,
        };
    }
}
