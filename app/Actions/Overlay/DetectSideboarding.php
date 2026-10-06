<?php

namespace App\Actions\Overlay;

use App\Actions\Logs\ConvertMtgoTimestamp;
use App\Enums\LogEventType;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use Carbon\Carbon;

class DetectSideboarding
{
    /**
     * How many recent sideboarding events to convert. MTGO emits a handful per
     * match; a bound keeps a pathological log from converting hundreds of rows
     * on a polling request.
     */
    private const CANDIDATE_LIMIT = 20;

    /**
     * A transition whose destination is a sideboarding state, across the
     * casual, league and tournament prefixes MTGO uses.
     */
    private const ENTERING_PATTERN = '/\bto \w*SideboardingState\b/';

    /**
     * Whether the local player is sideboarding right now.
     *
     * MTGO only enters a *JoinedSideboardingState once a game has ended, so a
     * transition logged after the most recent game began means that game is
     * over and the next one has not produced a snapshot yet. Once the next
     * game's first state event arrives, its `started_at` becomes the anchor
     * and the earlier transition no longer counts.
     *
     * The anchor is deliberately `started_at`, not `ended_at`. SyncGamePivots
     * advances `ended_at` as a "last activity seen" marker, and MTGO logs the
     * sideboarding transition in the same second the game ends (sometimes
     * seconds before the final game-log entry). Comparing against `ended_at`
     * dropped the real transition and only matched the later
     * `JoinedSideboardingState -> DeckSubmittedState` exit, so the overlay
     * flipped to sideboarding only after the deck was submitted.
     *
     * No `Game` row is projected until game 1 starts producing events, so a
     * pre-game-1 deck submission never reads as sideboarding.
     *
     * Live-match log events are safe to read here: PruneProcessedLogEvents'
     * normal pruneCompleted() path only deletes a match's events once it
     * reaches Complete. Its separate pruneStale() path unconditionally drops
     * anything older than 30 days as a hard cap against a stalled pipeline,
     * which a live-polled match's events are nowhere near.
     */
    public static function run(MtgoMatch $match): bool
    {
        /** @var string|null $lastGameStart */
        $lastGameStart = $match->games()->whereNotNull('started_at')->max('started_at');

        if (! $lastGameStart) {
            return false;
        }

        return self::latestSideboardingAt($match->token, Carbon::parse($lastGameStart)) !== null;
    }

    /**
     * The most recent transition *into* a sideboarding state for this match
     * that lands after the given moment, as a real UTC instant.
     *
     * Only the entering transition counts. The exits
     * (`JoinedSideboardingState -> DeckSubmitted/DeckAccepted`) also contain
     * "SideboardingState" and can share a second with the next game's first
     * state event, which would read as sideboarding for the whole game.
     *
     * `log_events.timestamp` is a raw HH:MM:SS string, so it is only
     * meaningful once combined with `logged_at` — comparing it directly
     * against a datetime would be a silent bug.
     */
    private static function latestSideboardingAt(string $token, Carbon $after): ?Carbon
    {
        $candidates = LogEvent::query()
            ->where('match_token', $token)
            ->where('event_type', LogEventType::MATCH_STATE_CHANGED->value)
            ->where('context', 'like', '%SideboardingState%')
            ->orderByDesc('id')
            ->limit(self::CANDIDATE_LIMIT)
            ->get(['id', 'context', 'timestamp', 'logged_at']);

        return $candidates
            ->filter(fn (LogEvent $event) => preg_match(self::ENTERING_PATTERN, (string) $event->context) === 1)
            ->map(fn (LogEvent $event) => ConvertMtgoTimestamp::run($event->logged_at, (string) $event->timestamp))
            ->filter(fn (Carbon $at) => $at->greaterThan($after))
            ->max();
    }
}
