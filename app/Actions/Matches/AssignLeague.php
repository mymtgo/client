<?php

namespace App\Actions\Matches;

use App\Actions\Drafts\ResolveDraftLeague;
use App\Actions\Leagues\CompleteLeague;
use App\Actions\Leagues\DeckFitsLeaguePool;
use App\Actions\Leagues\ResolveLeagueSetCode;
use App\Actions\Limited\ReadRegisteredDeck;
use App\Enums\DraftState;
use App\Enums\LeagueKind;
use App\Enums\LeagueState;
use App\Models\League;
use App\Models\LogEvent;
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

        // 1. Best path: find by event_id. Stamped on creation from the
        //    panel-view log event, or backfilled by ProcessLeagueEvents.
        //    Active-only: Partial leagues are dropped runs — new matches
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
        //    splits correctly. NULL deck_version_id on a league is legacy —
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
        if ($league && self::poolRejects($league, $match)) {
            Log::channel('pipeline')->info("Match {$match->mtgo_id}: deck does not fit league #{$league->id} pool, minting new run");

            if ($league->matches()->count() >= ResolveDraftLeague::COMPLETE_MATCH_COUNT) {
                CompleteLeague::run($league);
            } else {
                $league->update(['state' => LeagueState::Partial]);
            }

            $league = null;
        }

        // 2.5. Reject the match if the candidate league is already at the
        //      5-match cap. Backstops the unwatched re-entry edge: if app
        //      missed both the drop and re-join events, the next match for
        //      the new run must not glue onto the full prior run. Five
        //      matches = full MTGO league run, so the prior run is Complete
        //      (not Partial). The safety-net branch below mints a fresh
        //      league for the new run.
        if ($league && $league->matches()->count() >= 5) {
            CompleteLeague::run($league);
            $league = null;
        }

        // The most recent matching panel-view log event carries the LeagueID
        // and the real join time. Match logs don't expose EventId in a
        // key=value form ExtractKeyValueBlock can parse (they emit
        // "Event Id:NNNN" without an equals sign), so this is the only route
        // to it. Read once and shared by steps 2.9 and 3.
        $isNew = false;
        $panelView = null;

        if (! $league) {
            $panelView = LogEvent::where('event_type', 'league_joined')
                ->where('match_token', $gameMeta['League Token'])
                ->whereNotNull('match_id')
                ->orderByDesc('logged_at')
                ->first();
        }

        // 2.9. Heal a placeholder-token limited league. ResolveDraftLeague
        //      mints "draft-{leagueId}-{courseId}" when the draft lines
        //      arrived before any league_joined panel view, so steps 2 and
        //      2.2 (which look up the real League Token) cannot find the run
        //      that owns this match and step 3 would split it permanently.
        //      The panel view is what finally ties the real token to the
        //      LeagueID, so adopt the placeholder run and stamp the real
        //      token on it. The pool guard applies here too: without it a
        //      match from a later, unwatched re-entry would glue onto the
        //      previous run purely because that run's token was a
        //      placeholder.
        if (! $league && $panelView) {
            $placeholder = League::query()
                ->where('event_id', (int) $panelView->match_id)
                ->where('state', LeagueState::Active)
                ->whereIn('kind', [LeagueKind::Draft, LeagueKind::Sealed])
                ->where('token', 'like', ResolveDraftLeague::PLACEHOLDER_PREFIX.'%')
                ->latest('started_at')
                ->first();

            if ($placeholder && ! self::poolRejects($placeholder, $match)) {
                $placeholder->update(['token' => $gameMeta['League Token']]);
                $league = $placeholder;

                Log::channel('pipeline')->info("Match {$match->mtgo_id}: healed placeholder token on league #{$placeholder->id}", [
                    'token' => $gameMeta['League Token'],
                ]);
            }
        }

        // 3. Create the league. AssignLeague is the sole creator: leagues
        //    are minted when a match arrives carrying a League Token but no
        //    matching league exists. This guarantees deck_version_id is
        //    known at creation time.
        if (! $league) {
            $league = League::create([
                'token' => $gameMeta['League Token'],
                'event_id' => $panelView ? (int) $panelView->match_id : null,
                'format' => $gameMeta['PlayFormatCd'],
                'deck_version_id' => $match->deck_version_id,
                'started_at' => $match->started_at ?? now(),
                'joined_at' => $panelView?->logged_at,
                'name' => trim(($gameMeta['GameStructureCd'] ?? '').' League '.now()->toLocal()->format('d-m-Y h:ma')),
                'kind' => str_starts_with((string) ($gameMeta['PlayFormatCd'] ?? ''), 'D') ? LeagueKind::Draft : LeagueKind::Constructed,
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

        ResolveLeagueSetCode::run($league, $gameMeta['PlayFormatCd'] ?? null);

        Log::channel('pipeline')->info("Match {$match->mtgo_id}: assigned to league #{$league->id}", [
            'league_name' => $league->name,
            'has_league_token' => true,
        ]);
    }

    /**
     * True when this match's registered deck cannot have been built from the
     * league's pool, so the match belongs to a different run.
     *
     * Only limited leagues have a pool, and only a Finished draft has all of
     * it: a catch-up replay can still be projecting picks, and comparing a
     * registered deck against a half-built pool reads as low coverage and
     * would wrongly split the league. Anything it cannot tell is not a
     * rejection.
     */
    private static function poolRejects(League $league, MtgoMatch $match): bool
    {
        if (! $league->kind->isLimited() || ! $match->token) {
            return false;
        }

        $draft = $league->draft;

        if (! $draft || $draft->state !== DraftState::Finished) {
            return false;
        }

        $cards = ReadRegisteredDeck::run($match);

        if ($cards === null) {
            return false;
        }

        return ! DeckFitsLeaguePool::run($league, ReadRegisteredDeck::mainDeck($cards));
    }
}
