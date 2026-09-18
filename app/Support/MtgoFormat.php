<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * The one place that knows the shape of an MTGO constructed format code.
 * MTGO prefixes constructed codes with C for Constructed (CSTANDARD,
 * CModern) and limited codes with D (DHOBHOBHOB, see
 * MtgoMatch::isLimitedFormatCode()). The archetypes table and the UI both
 * want the bare constructed name, so the C comes off here and nowhere else:
 * two lookups that drifted apart have each already shipped a bug where
 * Standard and Pioneer matched no archetype. Limited codes pass through
 * untouched, since they have no archetype key and are labelled elsewhere.
 */
final class MtgoFormat
{
    /**
     * Human label: CSTANDARD and standard both give "Standard".
     */
    public static function display(?string $format): string
    {
        if ($format === null || $format === '') {
            return '';
        }

        // Only a C followed by another capital is a prefix: "Commander" is
        // a format name, "CModern" is a code.
        $raw = preg_match('/^C[A-Z]/', $format) ? substr($format, 1) : $format;

        return Str::title(strtolower($raw));
    }

    /**
     * The archetypes.format value: CSTANDARD, Standard and standard all give
     * "standard".
     */
    public static function key(?string $format): string
    {
        return strtolower(self::display($format));
    }
}
