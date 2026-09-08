<?php

namespace App\Support;

/**
 * Canonical form for a colour identity string: uppercase WUBRGC letters,
 * de-duplicated, joined with commas ("U,B"). Accepts the concatenated form
 * the archetype API sends ("UB") as well as the comma form the app writes.
 */
final class ColorIdentity
{
    public const SEPARATOR = ',';

    private const ALLOWED = ['W', 'U', 'B', 'R', 'G', 'C'];

    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $letters = [];

        foreach (str_split(strtoupper($value)) as $char) {
            if (in_array($char, self::ALLOWED, true) && ! in_array($char, $letters, true)) {
                $letters[] = $char;
            }
        }

        return $letters === [] ? null : implode(self::SEPARATOR, $letters);
    }
}
