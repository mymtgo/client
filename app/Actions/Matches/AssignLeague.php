<?php

namespace App\Actions\Matches;

use App\Enums\LeagueState;
use App\Models\League;
use App\Models\MtgoMatch;
use Illuminate\Support\Facades\Log;

class AssignLeague
{
    /**
     * Assign a league to the match. Real leagues only; matches without a
     * League Token (or tournament matches) remain unattached.
     */
    public static function run(MtgoMatch $match, array $gameMeta): void
    {
        // Tournament matches are handled separately — no league assignment.
        // Prefer the match column (stamped by AdvanceMatchState) because
        // $gameMeta['Description'] is unreliable for single-line logs where
        // ExtractKeyValueBlock can't split keys correctly.
        $isTournament = $match->tournament_event_id !== null
            || preg_match('/Tournament:\d+\s+Round:\d+/', $gameMeta['Description'] ?? '');

        if ($isTournament) {
            return;
        }

        if (empty($gameMeta['League Token'])) {
            return;
        }

        $league = null;

        // 1. Best path: find by event_id (set by ProcessLeagueEvents)
        //    Active-only: Partial leagues are dropped runs — new matches
        //    should never attach to them.
        if (! empty($gameMeta['EventId'])) {
            $league = League::where('event_id', (int) $gameMeta['EventId'])
                ->where('state', LeagueState::Active)
                ->latest('started_at')
                ->first();
        }

        // 2. Fallback: find by token + Active.
        //    Format is intentionally not part of the filter: MTGO emits
        //    different PlayFormatCd codes for the same league across log
        //    contexts (panel view: "Modern"; match log: "CMODERN"), and
        //    ProcessLeagueEvents stores the panel-view value while this
        //    action sees the match-log value. Token alone is unique per
        //    league run.
        //
        //    deck_version_id distinguishes runs across the app-not-watching
        //    re-entry case (drop + re-enter with a new deck while the app
        //    is closed): A's deck v1 ≠ match's v2 → step 2 misses, step 3
        //    splits correctly. We accept NULL on the league side because
        //    ProcessLeagueEvents creates leagues at join time without deck
        //    context — the first match backfills it.
        if (! $league) {
            $league = League::where('token', $gameMeta['League Token'])
                ->where('state', LeagueState::Active)
                ->when($match->deck_version_id, fn ($q, $deckVersionId) => $q->where(
                    fn ($inner) => $inner->whereNull('deck_version_id')
                        ->orWhere('deck_version_id', $deckVersionId),
                ))
                ->latest('started_at')
                ->first();

            if ($league && ! $league->deck_version_id && $match->deck_version_id) {
                $league->update(['deck_version_id' => $match->deck_version_id]);
            }
        }

        // 2.5. Reject the match if the candidate league is already at the
        //      5-match cap. Backstops the unwatched re-entry edge: if app
        //      missed both the drop and re-join events, the next match for
        //      the new run must not glue onto the full prior run. Five
        //      matches = full MTGO league run, so the prior run is Complete
        //      (not Partial). The safety-net branch below mints a fresh
        //      league for the new run.
        if ($league && $league->matches()->count() >= 5) {
            $league->update(['state' => LeagueState::Complete]);
            $league = null;
        }

        // 3. Safety net: create reactively
        $isNew = false;
        if (! $league) {
            $league = League::create([
                'token' => $gameMeta['League Token'],
                'format' => $gameMeta['PlayFormatCd'],
                'deck_version_id' => $match->deck_version_id,
                'started_at' => now(),
                'name' => trim(($gameMeta['GameStructureCd'] ?? '').' League '.now()->toLocal()->format('d-m-Y h:ma')),
            ]);
            $isNew = true;
        }

        if ($isNew) {
            // Mark older active leagues with the same token as partial.
            // Format intentionally excluded — see step 2.
            League::where('token', $gameMeta['League Token'])
                ->where('state', LeagueState::Active)
                ->where('id', '!=', $league->id)
                ->where('started_at', '<=', $league->started_at)
                ->update(['state' => LeagueState::Partial]);
        }

        $match->update(['league_id' => $league->id]);

        Log::channel('pipeline')->info("Match {$match->mtgo_id}: assigned to league #{$league->id}", [
            'league_name' => $league->name,
            'has_league_token' => true,
        ]);
    }
}
