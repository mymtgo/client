<?php

namespace App\Actions\Matches;

class DecodeMetaMessageText
{
    /**
     * Result-phrase regex patterns — every variant ExtractGameResults consumes.
     */
    private const PATTERNS = [
        '/@P[A-Za-z0-9_-]+ rolled a \d+\./',
        '/@P@P[A-Za-z0-9_-]+ joined the game\./',
        '/@P[A-Za-z0-9_-]+ chooses to play (first|second)\./',
        '/@P[A-Za-z0-9_-]+ begins the game with \w+ cards? in hand\./',
        '/@P[A-Za-z0-9_-]+ wins the game\./',
        '/@P[A-Za-z0-9_-]+ has conceded from the game\./',
        '/@P[A-Za-z0-9_-]+ has lost connection to the game\./',
        '/@P[A-Za-z0-9_-]+ (?:leads|wins) the match \d+-\d+/',
        '/Match Tied \d+-\d+/',
    ];

    /**
     * Extract the human-readable text payload from a MetaMessage byte array.
     *
     * Returns null if the frame carries no recognised chat text (state-update
     * binaries, dialog prompts, etc.).
     *
     * @param  array<int, int>  $bytes
     */
    public static function run(array $bytes): ?string
    {
        if (count($bytes) < 24) {
            return null;
        }

        $text = self::bytesToAsciiCandidate($bytes);

        if ($text === null) {
            return null;
        }

        foreach (self::PATTERNS as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                return $m[0];
            }
        }

        return null;
    }

    /**
     * Convert byte array to a candidate ASCII string for regex matching.
     * Replaces non-printable bytes with a sentinel so regex matches can't
     * accidentally span structural binary header fields.
     */
    private static function bytesToAsciiCandidate(array $bytes): ?string
    {
        $out = '';
        foreach ($bytes as $b) {
            $b = (int) $b;
            if ($b >= 32 && $b < 127) {
                $out .= chr($b);
            } else {
                $out .= "\x00";
            }
        }

        return $out === '' ? null : $out;
    }
}
