<?php

namespace App\Actions\Matches;

use App\Enums\MatchState;

/**
 * Pure state-machine: given a current MatchState and a raw MTGO state-name
 * string (lifted from a log event context), return the next MatchState — or
 * null when the transition is not modelled.
 *
 * No DB writes, no side effects, no I/O.
 */
class TransitionMatchState
{
    /**
     * Raw MTGO state-name fragments that signal a match has ended.
     *
     * @var array<int, string>
     */
    private const END_SIGNALS = [
        'TournamentMatchClosedState',
        'MatchCompletedState',
        'MatchEndedState',
        'MatchClosedState',
        'JoinedCompletedState',
    ];

    /**
     * Raw MTGO state-name fragments that signal a match has been joined
     * (the gate for creating a match in Started state).
     *
     * @var array<int, string>
     */
    private const JOIN_SIGNALS = [
        'MatchJoinedEventUnderwayState',
    ];

    /**
     * Map current state + raw next-state name to the next MatchState.
     * Returns null when the transition is invalid or not modelled.
     */
    public static function run(MatchState $current, string $rawNextStateName): ?MatchState
    {
        // Terminal states never advance.
        if ($current === MatchState::Complete || $current === MatchState::Ended) {
            return null;
        }

        if ($current === MatchState::InProgress && self::matchesAny($rawNextStateName, self::END_SIGNALS)) {
            return MatchState::Ended;
        }

        if ($current === MatchState::Started && self::matchesAny($rawNextStateName, self::JOIN_SIGNALS)) {
            return MatchState::Started;
        }

        return null;
    }

    /**
     * True when $haystack contains any of the supplied signal fragments.
     */
    public static function isEndSignal(?string $haystack): bool
    {
        return $haystack !== null && self::matchesAny($haystack, self::END_SIGNALS);
    }

    /**
     * True when $haystack contains any of the join-signal fragments.
     */
    public static function isJoinSignal(?string $haystack): bool
    {
        return $haystack !== null && self::matchesAny($haystack, self::JOIN_SIGNALS);
    }

    /**
     * @param  array<int, string>  $needles
     */
    private static function matchesAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
