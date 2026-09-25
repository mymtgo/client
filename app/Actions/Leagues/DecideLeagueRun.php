<?php

namespace App\Actions\Leagues;

use App\Enums\LeagueState;
use App\Sidecar\LeagueCandidate;
use App\Sidecar\LeagueRunDecision;
use App\Sidecar\LeagueSnapshot;

class DecideLeagueRun
{
    /**
     * Pure: which league run this match belongs to (spec 5.3). The counters
     * say whether this is the first match of a run; game_history says which
     * run an existing league is. No queries, no writes.
     *
     * @param  list<LeagueCandidate>  $candidates
     */
    public static function run(LeagueSnapshot $snapshot, array $candidates, int $defaultTotal): LeagueRunDecision
    {
        $newRun = $snapshot->isNewRun();
        $prior = $snapshot->priorMatchCount();

        if ($newRun === null || $prior === null) {
            return LeagueRunDecision::undecided();
        }

        $total = $snapshot->totalMatches ?? $defaultTotal;

        $target = $newRun
            ? self::firstEmpty($candidates)
            : self::firstBelonging($candidates, $snapshot, $prior);

        $close = [];

        foreach ($candidates as $candidate) {
            if ($candidate === $target || $candidate->state !== LeagueState::Active) {
                continue;
            }

            $isOtherRun = $newRun
                ? $candidate->hasOtherMatches()
                : ! self::belongs($candidate, $snapshot, $prior);

            if ($isOtherRun) {
                $close[$candidate->id] = count($candidate->otherMatchMtgoIds) >= $total ? LeagueState::Complete : LeagueState::Partial;
            }
        }

        if ($target === null) {
            return new LeagueRunDecision(null, true, false, $close);
        }

        return new LeagueRunDecision($target->id, false, $target->state === LeagueState::Partial, $close);
    }

    /**
     * A candidate is this run when every one of its other matches is in the
     * run's history. A history the SDK could not read falls back to counting:
     * it is never read as "no games", which would close real runs.
     */
    private static function belongs(LeagueCandidate $candidate, LeagueSnapshot $snapshot, int $prior): bool
    {
        if ($snapshot->historyMatchIds === null) {
            // Counting cannot tell an old closed run from this one, so a
            // Partial league is never reopened on counts alone.
            return $candidate->state !== LeagueState::Partial && count($candidate->otherMatchMtgoIds) <= $prior;
        }

        return array_diff($candidate->otherMatchMtgoIds, $snapshot->historyMatchIds) === [];
    }

    /** @param list<LeagueCandidate> $candidates */
    private static function firstEmpty(array $candidates): ?LeagueCandidate
    {
        foreach ($candidates as $candidate) {
            if (! $candidate->hasOtherMatches() && $candidate->state === LeagueState::Active) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The belonging candidate to attach to: one with matches before an
     * empty one, then Active before Partial.
     *
     * @param  list<LeagueCandidate>  $candidates
     */
    private static function firstBelonging(array $candidates, LeagueSnapshot $snapshot, int $prior): ?LeagueCandidate
    {
        $belonging = array_values(array_filter($candidates, fn (LeagueCandidate $c) => self::belongs($c, $snapshot, $prior)));

        usort($belonging, function (LeagueCandidate $a, LeagueCandidate $b): int {
            if ($a->hasOtherMatches() !== $b->hasOtherMatches()) {
                return $a->hasOtherMatches() ? -1 : 1;
            }

            return ($a->state === LeagueState::Partial) <=> ($b->state === LeagueState::Partial);
        });

        return $belonging[0] ?? null;
    }
}
