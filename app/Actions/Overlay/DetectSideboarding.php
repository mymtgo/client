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
     * Whether the local player is sideboarding right now.
     *
     * MTGO emits a *JoinedSideboardingState transition both when submitting a
     * deck before game 1 and between games. What excludes the pre-game-1 case
     * is not `ended_at` meaning "finished" — SyncGamePivots advances it on
     * every pipeline tick while a game is still being played, so it is a
     * "last activity seen" marker, never a completion flag — it's that no
     * `Game` row is projected at all until game 1 starts producing events.
     * Before that, `$match->games()` is empty and the guard below returns
     * false regardless of any log event.
     *
     * Live-match log events are safe to read here: PruneProcessedLogEvents'
     * normal pruneCompleted() path only deletes a match's events once it
     * reaches Complete. Its separate pruneStale() path unconditionally drops
     * anything older than 30 days as a hard cap against a stalled pipeline,
     * which a live-polled match's events are nowhere near.
     */
    public static function run(MtgoMatch $match): bool
    {
        $lastGameEnd = $match->games()->whereNotNull('ended_at')->max('ended_at');

        if (! $lastGameEnd) {
            return false;
        }

        $lastGameEnd = Carbon::parse($lastGameEnd);

        $latest = self::latestSideboardingAt($match->token, $lastGameEnd);

        if (! $latest) {
            return false;
        }

        $resumed = $match->games()
            ->where('started_at', '>=', $latest)
            ->whereHas('timeline')
            ->exists();

        return ! $resumed;
    }

    /**
     * The most recent sideboarding transition for this match that lands after
     * the given moment, as a real UTC instant.
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
            ->map(fn (LogEvent $event) => ConvertMtgoTimestamp::run($event->logged_at, (string) $event->timestamp))
            ->filter(fn (Carbon $at) => $at->greaterThan($after))
            ->max();
    }
}
