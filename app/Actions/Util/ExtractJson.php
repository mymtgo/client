<?php

namespace App\Actions\Util;

use Illuminate\Support\Collection;

class ExtractJson
{
    public static function run(string $text): Collection
    {

        $results = [];
        $len = strlen($text);

        for ($i = 0; $i < $len; $i++) {
            $ch = $text[$i];

            if ($ch !== '{' && $ch !== '[') {
                continue;
            }

            $jsonString = static::extractBalancedJsonSubstring($text, $i);
            if ($jsonString === null) {
                continue;
            }

            $decoded = json_decode($jsonString, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $results[] = $decoded;

                // Skip past this JSON blob to avoid re-detecting inner braces
                $i += strlen($jsonString) - 1;
            }
        }

        return collect($results);
    }

    /**
     * Returns the balanced JSON substring starting at $start, or null if not balanced.
     * String-aware and escape-aware.
     */
    protected static function extractBalancedJsonSubstring(string $text, int $start): ?string
    {
        $len = strlen($text);
        $open = $text[$start];
        $close = $open === '{' ? '}' : ']';

        $depth = 0;
        $inString = false;
        $escape = false;

        for ($i = $start; $i < $len; $i++) {
            $ch = $text[$i];

            if ($inString) {
                if ($escape) {
                    $escape = false;

                    continue;
                }
                if ($ch === '\\') {
                    $escape = true;

                    continue;
                }
                if ($ch === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($ch === '"') {
                $inString = true;

                continue;
            }

            if ($ch === $open) {
                $depth++;

                continue;
            }

            if ($ch === $close) {
                $depth--;
                if ($depth === 0) {
                    return substr($text, $start, ($i - $start) + 1);
                }
            }
        }

        return null;
    }
}
