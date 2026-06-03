<?php

namespace App\Actions\Matches;

use App\Enums\MatchState;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AbandonStaleMatches
{
    /**
     * Mark in_progress matches that will never resolve as Abandoned.
     *
     * MTGO writes no closing state when the client is killed mid-match (e.g.
     * quitting during sideboarding), leaving the match stuck in_progress
     * forever. A match is abandoned when it carries no match-end signal AND
     * has seen no new log activity within the configured window. Matches that
     * DO carry an end signal are left untouched — those are resolvable by
     * reprocessing in ProcessMatchEvents.
     */
    public static function run(): void
    {
        $cutoff = now()->subMinutes((int) config('mtgo.match_abandon_after_minutes', 60));

        MtgoMatch::query()
            ->where('state', MatchState::InProgress)
            ->whereNull('failed_at')
            ->get()
            ->each(fn (MtgoMatch $match) => self::evaluate($match, $cutoff));
    }

    private static function evaluate(MtgoMatch $match, Carbon $cutoff): void
    {
        $stateChanges = LogEvent::where('match_token', $match->token)
            ->where('event_type', 'match_state_changed')
            ->get();

        if (self::hasEndSignal($stateChanges)) {
            return;
        }

        $lastActivity = self::lastActivityAt($match);

        if ($lastActivity === null || $lastActivity->greaterThan($cutoff)) {
            return;
        }

        $match->update([
            'state' => MatchState::Abandoned,
            'ended_at' => $lastActivity,
        ]);

        // Stop the match's trailing events from being rediscovered each tick.
        LogEvent::where('match_token', $match->token)
            ->whereNull('processed_at')
            ->update(['processed_at' => now()]);

        Log::channel('pipeline')->info("Match {$match->mtgo_id}: marked Abandoned (no end signal, inactive since {$lastActivity->toDateTimeString()})");
    }

    /**
     * Most recent log activity for the match. match_state_changed and
     * game_management_json carry match_token; game_state_update carries only
     * match_id — so both columns must be considered.
     */
    private static function lastActivityAt(MtgoMatch $match): ?Carbon
    {
        $latest = LogEvent::query()
            ->where('match_token', $match->token)
            ->orWhere('match_id', $match->mtgo_id)
            ->max('logged_at');

        return $latest ? Carbon::parse($latest) : null;
    }

    /**
     * Mirrors AdvanceMatchState::tryAdvanceToEnded — if any of these signals is
     * present the match can still advance through the normal pipeline.
     *
     * @param  Collection<int, LogEvent>  $stateChanges
     */
    private static function hasEndSignal(Collection $stateChanges): bool
    {
        $serverEnded = $stateChanges->contains(
            fn (LogEvent $event) => Str::contains($event->context ?? '', [
                'TournamentMatchClosedState',
                'MatchCompletedState',
                'MatchEndedState',
                'MatchClosedState',
                'JoinedCompletedState',
            ])
        );

        return $serverEnded || DetermineMatchResult::localPlayerConceded($stateChanges);
    }
}
