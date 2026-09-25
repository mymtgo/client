<?php

namespace App\Actions\Leagues;

use App\Enums\LeagueKind;
use App\Enums\LeagueState;
use App\Models\League;
use App\Models\MtgoMatch;

class FindLogLeagueCandidate
{
    /**
     * The Active league the log path would attach this match to, before the
     * pool, round-cap and heal checks (AssignLeague steps 1, 2 and 2.2).
     *
     * @param  array<string, mixed>  $gameMeta
     */
    public static function run(MtgoMatch $match, array $gameMeta): ?League
    {
        $league = null;

        // 1. Best path: find by event_id. Stamped on creation from the
        //    panel-view log event, or backfilled by ProcessLeagueEvents.
        //    Active-only: Partial leagues are dropped runs: new matches
        //    should never attach to them.
        if (! empty($gameMeta['EventId'])) {
            $league = League::where('event_id', (int) $gameMeta['EventId'])
                ->where('state', LeagueState::Active)
                ->latest('started_at')
                ->first();
        }

        // 2. Fallback: find by token + Active.
        //    Format is intentionally not part of the filter: legacy data
        //    holds leagues created from panel-view logs (PlayFormatCd=Modern)
        //    while this action sees match-log values (PlayFormatCd=CMODERN).
        //    Token alone is unique per league run.
        //
        //    deck_version_id distinguishes runs across the app-not-watching
        //    re-entry case (drop + re-enter with a new deck while the app
        //    is closed): A's deck v1 ≠ match's v2 → step 2 misses, step 3
        //    splits correctly. NULL deck_version_id on a league is legacy ,
        //    new leagues are always created with a known deck.
        if (! $league) {
            $league = League::where('token', $gameMeta['League Token'])
                ->where('state', LeagueState::Active)
                ->when($match->deck_version_id, fn ($q, $deckVersionId) => $q->where(
                    fn ($inner) => $inner->whereNull('deck_version_id')
                        ->orWhere('deck_version_id', $deckVersionId),
                ))
                ->latest('started_at')
                ->first();

            // Limited leagues are excluded here: the limited block in
            // AdvanceMatchState is what tracks a limited league's latest
            // deck_version_id (it changes legitimately match to match, not
            // once at re-entry). Backfilling it here from whichever match
            // happens to run first pollutes the value step 2's own dv filter
            // then reads on the next match, causing a false miss and a
            // spurious extra league.
            if ($league && ! $league->deck_version_id && $match->deck_version_id && ! $league->kind->isLimited()) {
                $league->update(['deck_version_id' => $match->deck_version_id]);
            }
        }

        // 2.2. Limited-kind fallback, without the dv filter from step 2. Limited
        //      leagues legitimately change decks between matches (rebuilds,
        //      basics swaps), so the constructed dv-split heuristic in step 2
        //      must not apply to them; the pool guard at step 2.4 is the real
        //      limited re-entry detector. Concretely: AdvanceMatchState mints a
        //      synthetic Limited DeckVersion after the run's first match, so by
        //      the second match $match->deck_version_id already differs from
        //      the league's stored value and step 2's dv filter misses the
        //      league that owns this run, minting a spurious extra league.
        //      No dv backfill here: the limited block in AdvanceMatchState
        //      already tracks the league's latest deck_version_id.
        if (! $league && $match->deck_version_id) {
            $league = League::where('token', $gameMeta['League Token'])
                ->where('state', LeagueState::Active)
                ->whereIn('kind', [LeagueKind::Draft, LeagueKind::Sealed])
                ->latest('started_at')
                ->first();
        }

        return $league;
    }
}
