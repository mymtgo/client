<?php

namespace App\Actions\Leagues;

use App\Actions\Matches\ReadJoinedGameMeta;
use App\Actions\Sidecar\ReadMatchSnapshot;
use App\Actions\Sidecar\RecordFieldDiff;
use App\Actions\Sidecar\ResolveFieldAuthority;
use App\Enums\LeagueKind;
use App\Enums\LeagueState;
use App\Models\League;
use App\Models\MtgoMatch;
use App\Sidecar\LeagueCandidate;
use App\Sidecar\LeagueSnapshot;
use App\Sidecar\SidecarAuthorityFlags;
use App\Sidecar\SidecarPaths;

class ResolveLeagueRunFromSidecar
{
    /**
     * First answer for a match with no league yet (spec 5.3), called inline
     * by AssignLeague. Returns false when the sidecar cannot speak for this
     * match, so the log path decides.
     *
     * The token cross-check only proves the snapshot is this match's (the
     * token is per season); which run it is comes from DecideLeagueRun. Log
     * events it needs may have been pruned, which counts as failing.
     *
     * @param  array<string, mixed>|null  $gameMeta  the joined-state block when the caller has it
     */
    public static function run(MtgoMatch $match, ?array $gameMeta = null): bool
    {
        $snapshot = self::snapshotFor($match, onlyNew: false);

        return $snapshot !== null && self::decide($match, $snapshot, $gameMeta);
    }

    /**
     * Late correction from ApplySidecarProjection, for a match the log path
     * already assigned. Deliberately narrow, because every other case is a
     * league the user can see as settled:
     *
     * - only a snapshot that has just arrived (a decision is made once, not
     *   re-litigated every tick);
     * - only while the match's league is Active: a Complete or Dropped run
     *   is final, and correcting its last match would split it;
     * - only for the newest match of that league series: re-projecting an
     *   older match must not reopen its run or close the current one.
     */
    public static function correct(MtgoMatch $match): void
    {
        $league = $match->league;

        if ($league === null || $league->manual || $league->state !== LeagueState::Active) {
            return;
        }

        $snapshot = self::snapshotFor($match, onlyNew: true);

        if ($snapshot === null) {
            return;
        }

        $newerMatchExists = MtgoMatch::query()
            ->whereKeyNot($match->id)
            ->where('started_at', '>', $match->started_at)
            ->whereHas('league', fn ($q) => $q->where('token', $league->token))
            ->exists();

        if ($newerMatchExists) {
            return;
        }

        self::decide($match, $snapshot, null);
    }

    private static function snapshotFor(MtgoMatch $match, bool $onlyNew): ?LeagueSnapshot
    {
        if (! SidecarAuthorityFlags::isOn('league_run') || ! is_dir(SidecarPaths::directory())) {
            return null;
        }

        if ($match->manual || $match->submitted_at !== null || MtgoMatch::isLimitedFormatCode($match->format)) {
            return null;
        }

        $read = ReadMatchSnapshot::run($match, ReadMatchSnapshot::PHASE_STARTED);

        if ($read === null || ($onlyNew && ! $read->isNew) || $read->league?->token === null) {
            return null;
        }

        return $read->league;
    }

    /** @param array<string, mixed>|null $gameMeta */
    private static function decide(MtgoMatch $match, LeagueSnapshot $snapshot, ?array $gameMeta): bool
    {
        $logToken = ($gameMeta ?? ReadJoinedGameMeta::run($match) ?? [])['League Token'] ?? null;

        if ($logToken !== $snapshot->token) {
            $resolution = ResolveFieldAuthority::run('league_run', $logToken, $snapshot->token, true, true, true, false);
            RecordFieldDiff::run($match, null, 'league_run', $resolution, $logToken, $snapshot->token);

            return false;
        }

        $candidates = FindSidecarLeagueCandidates::run($snapshot, [LeagueState::Active, LeagueState::Partial])
            ->map(fn (League $league) => new LeagueCandidate(
                $league->id,
                $league->state,
                $league->matches->where('id', '!=', $match->id)->pluck('mtgo_id')->map(fn ($id) => (string) $id)->values()->all(),
            ))
            ->all();

        $decision = DecideLeagueRun::run($snapshot, $candidates, LeagueKind::Constructed->roundCount());

        if ($decision->isUndecided()) {
            return false;
        }

        $current = $match->league_id;
        $league = ApplyLeagueRunDecision::run($match, $decision, $snapshot);

        // Recorded only when the sidecar replaced a log answer, and never
        // cleared on a later agreeing pass: the row is the evidence the log
        // got it wrong.
        if ($current !== null && $current !== $league->id) {
            RecordFieldDiff::run($match, null, 'league_run', ResolveFieldAuthority::run('league_run', $current, $league->id, true, true, true, true), $current, $league->id);
        }

        return true;
    }
}
