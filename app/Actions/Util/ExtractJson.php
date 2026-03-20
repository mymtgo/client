<?php

namespace App\Actions\Util;

use Illuminate\Support\Collection;

class ExtractJson
{
    public static function run(string $text): Collection
    {
        // Fast path: entire text is valid JSON (covers the majority of callers).
        $decoded = json_decode($text, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return collect([$decoded]);
        }

        $results = [];
        $len = strlen($text);
        $scanBudget = min($len * 3, 500_000);
        $scanned = 0;

        for ($i = 0; $i < $len; $i++) {
            $ch = $text[$i];

            if ($ch !== '{' && $ch !== '[') {
                continue;
            }

            $jsonString = static::extractBalancedJsonSubstring($text, $i, $scanBudget, $scanned);
            if ($jsonString === null) {
                if ($scanned >= $scanBudget) {
                    break;
                }

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
    protected static function extractBalancedJsonSubstring(string $text, int $start, int $scanBudget, int &$scanned): ?string
    {
        $len = strlen($text);
        $open = $text[$start];
        $close = $open === '{' ? '}' : ']';

        $depth = 0;
        $inString = false;
        $escape = false;

        for ($i = $start; $i < $len; $i++) {
            $scanned++;
            if ($scanned >= $scanBudget) {
                return null;
            }

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
