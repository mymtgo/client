<?php

namespace App\Actions\Util;

class RepairJson
{
    private const MAX_PADDING = 3;

    /**
     * Decode the first JSON object in a log line, tolerating MTGO payloads
     * that are missing up to three closing brackets at the end.
     *
     * @return array<string, mixed>|null
     */
    public static function firstObject(string $text): ?array
    {
        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        if ($start === false) {
            return null;
        }

        $candidate = $end === false || $end < $start
            ? substr($text, $start)
            : substr($text, $start, $end - $start + 1);

        for ($pad = 0; $pad <= self::MAX_PADDING; $pad++) {
            $decoded = json_decode($candidate.self::closers($candidate, $pad), true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * Build the closing sequence for the innermost $count unclosed brackets,
     * reading the candidate's own open/close balance so `]` and `}` land in
     * the right order.
     */
    private static function closers(string $candidate, int $count): string
    {
        if ($count === 0) {
            return '';
        }

        $stack = [];
        $inString = false;
        $escape = false;
        $len = strlen($candidate);

        for ($i = 0; $i < $len; $i++) {
            $ch = $candidate[$i];

            if ($inString) {
                if ($escape) {
                    $escape = false;
                } elseif ($ch === '\\') {
                    $escape = true;
                } elseif ($ch === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($ch === '"') {
                $inString = true;
            } elseif ($ch === '{' || $ch === '[') {
                $stack[] = $ch === '{' ? '}' : ']';
            } elseif ($ch === '}' || $ch === ']') {
                array_pop($stack);
            }
        }

        $missing = array_reverse(array_slice($stack, -$count));

        return implode('', $missing);
    }
}
