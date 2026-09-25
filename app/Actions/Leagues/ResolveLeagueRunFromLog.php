<?php

namespace App\Actions\Leagues;

use App\Models\LogEvent;
use App\Models\MtgoMatch;
use Illuminate\Support\Facades\Log;

class ResolveLeagueRunFromLog
{
    /**
     * Assign the match to a league run from log signals alone: the path
     * every machine without the sidecar takes, unchanged in behaviour.
     *
     * @param  array<string, mixed>  $gameMeta
     */
    public static function run(MtgoMatch $match, array $gameMeta): void
    {
        $token = $gameMeta['League Token'];

        $league = FindLogLeagueCandidate::run($match, $gameMeta);

        // 2.4. Limited re-entry guard. A draft league's matches must be played
        //      with a deck built from that draft's pool. If the app missed the
        //      next draft (not watching), the resolved Active league is the
        //      OLD run; the pool check catches that and forces a fresh league.
        //      Runs after steps 1-2 so it checks whichever league they found:
        //      match logs don't carry EventId in a parseable form, so step 1
        //      never matches; this guard is what actually catches limited
        //      re-entry in practice.
        //
        //      Only fires once the draft's pool is fully known (state
        //      Finished). A catch-up replay can still be projecting picks
        //      when this runs; comparing a registered deck against a
        //      half-built pool reads as low coverage and would wrongly split
        //      the league. An unfinished draft means the check cannot tell,
        //      so it skips rather than guesses.
        if ($league && LeaguePoolRejectsMatch::run($league, $match)) {
            Log::channel('pipeline')->info("Match {$match->mtgo_id}: deck does not fit league #{$league->id} pool, minting new run");

            CloseLeagueRun::run($league);
            $league = null;
        }

        // 2.5. Reject the match if the candidate league is already at its
        //      round cap (three for draft, five for constructed and sealed).
        //      Backstops the unwatched re-entry edge: if app missed both the
        //      drop and re-join events, the next match for the new run must
        //      not glue onto the full prior run. A full round count = full
        //      MTGO league run, so the prior run is Complete (not Partial).
        //      The safety-net branch below mints a fresh league for the new
        //      run.
        if ($league && $league->matches()->count() >= $league->kind->roundCount()) {
            CompleteLeague::run($league);
            $league = null;
        }

        // The most recent matching panel-view log event carries the LeagueID
        // and the real join time. Match logs don't expose EventId in a
        // key=value form ExtractKeyValueBlock can parse (they emit
        // "Event Id:NNNN" without an equals sign), so this is the only route
        // to it. Read once and shared by steps 2.9 and 3.
        $panelView = $league ? null : LogEvent::where('event_type', 'league_joined')
            ->where('match_token', $token)
            ->whereNotNull('match_id')
            ->orderByDesc('logged_at')
            ->first();

        if (! $league && $panelView) {
            $league = HealPlaceholderLeague::run($match, $token, $panelView);
        }

        // 3. Create the league. AssignLeague is the sole creator: leagues
        //    are minted when a match arrives carrying a League Token but no
        //    matching league exists. This guarantees deck_version_id is
        //    known at creation time.
        $league ??= MintLeague::run(
            $match,
            $token,
            $panelView ? (int) $panelView->match_id : null,
            $gameMeta['PlayFormatCd'] ?? null,
            $gameMeta['GameStructureCd'] ?? null,
            $panelView?->logged_at,
        );

        $match->update(['league_id' => $league->id]);

        ResolveLeagueSetCode::run($league, $gameMeta['PlayFormatCd'] ?? null);

        Log::channel('pipeline')->info("Match {$match->mtgo_id}: assigned to league #{$league->id}", [
            'league_name' => $league->name,
            'has_league_token' => true,
        ]);
    }
}
