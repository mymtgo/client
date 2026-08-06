<?php

namespace App\Actions\Matches;

class DecodeMetaMessageText
{
    /**
     * Result-phrase regex patterns — every variant ExtractGameResults consumes.
     * Player names use ExtractGameResults::PLAYER_PATTERN so a charset change
     * (e.g. dotted usernames) can never diverge between decode and extraction.
     */
    private const PATTERNS = [
        // Result phrases
        '/@P'.ExtractGameResults::PLAYER_PATTERN.' rolled a \d+\./',
        '/@P@P'.ExtractGameResults::PLAYER_PATTERN.' joined the game\./',
        '/@P'.ExtractGameResults::PLAYER_PATTERN.' chooses to play (first|second)\./',
        '/@P'.ExtractGameResults::PLAYER_PATTERN.' begins the game with \w+ cards? in hand\./',
        '/@P'.ExtractGameResults::PLAYER_PATTERN.' wins the game\./',
        '/@P'.ExtractGameResults::PLAYER_PATTERN.' has conceded from the game\./',
        '/@P'.ExtractGameResults::PLAYER_PATTERN.' has lost connection to the game\./',
        '/@P'.ExtractGameResults::PLAYER_PATTERN.' (?:leads|wins) the match \d+-\d+/',
        '/Match Tied \d+-\d+/',

        // Card-action phrases (required by ExtractCardsFromGameLog)
        '/@P'.ExtractGameResults::PLAYER_PATTERN.' casts @\[[^@]+@:\d+,\d+:@\].*/',
        '/@P'.ExtractGameResults::PLAYER_PATTERN.' plays @\[[^@]+@:\d+,\d+:@\]/',
        '/@P'.ExtractGameResults::PLAYER_PATTERN.' activates an ability of @\[[^@]+@:\d+,\d+:@\]/',
        '/@P'.ExtractGameResults::PLAYER_PATTERN.' puts a triggered ability from @\[[^@]+@:\d+,\d+:@\]/',
        '/@P'.ExtractGameResults::PLAYER_PATTERN.' reveals @\[[^@]+@:\d+,\d+:@\] from their opening hand/',
        '/@P'.ExtractGameResults::PLAYER_PATTERN.' reveals \d+ cards? with @\[.*/',
        '/@P'.ExtractGameResults::PLAYER_PATTERN.' reveals @\[[^@]+@:\d+,\d+:@\]/',
        '/@P'.ExtractGameResults::PLAYER_PATTERN.' discards @\[[^@]+@:\d+,\d+:@\]/',
        '/@P'.ExtractGameResults::PLAYER_PATTERN.' puts @\[[^@]+@:\d+,\d+:@\] into their graveyard/',
        '/@P'.ExtractGameResults::PLAYER_PATTERN.' puts @\[[^@]+@:\d+,\d+:@\] onto the battlefield/',
        '/@P'.ExtractGameResults::PLAYER_PATTERN."'s @\\[[^@]+@:\\d+,\\d+:@\\]/",
        '/@P'.ExtractGameResults::PLAYER_PATTERN.' names .+ for @\[[^@]+@:\d+,\d+:@\]/',
        '/@P'.ExtractGameResults::PLAYER_PATTERN.' mulligans to .+/',
        '/@PTurn \d+:/',
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
