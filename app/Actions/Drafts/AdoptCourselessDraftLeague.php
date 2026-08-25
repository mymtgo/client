<?php

namespace App\Actions\Drafts;

use App\Enums\LeagueKind;
use App\Enums\LeagueState;
use App\Models\Draft;
use App\Models\League;
use App\Support\TimedTransaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class AdoptCourselessDraftLeague
{
    /**
     * A draft needs enough picks logged for its pool to be evidence of
     * anything, mirroring LinkUnlinkedDrafts.
     */
    private const MIN_PICKS_FOR_ADOPTION = 20;

    /**
     * Backfill on later log arrival (spec: Re-entry, rule 4 aftermath).
     *
     * The app was closed during the draft, so AssignLeague saw the run's
     * matches first and minted a draft league with no CourseID and no Draft
     * row for them. When the draft's own lines finally arrive, keying the
     * league on (event_id, CourseID) cannot see that row and mints a second
     * league for the same run: same event, one row holding the matches, one
     * holding the draft.
     *
     * The pool is what tells them apart, and it only exists once the picks
     * are projected, which is after ResolveDraftLeague has already run. So
     * this is a late pass: the run whose registered deck was built from this
     * draft's pool absorbs the CourseID and the draft, and the row minted
     * from the draft lines (which never held a match) goes away.
     */
    public static function run(): void
    {
        Draft::query()
            ->whereNotNull('league_id')
            ->has('picks', '>=', self::MIN_PICKS_FOR_ADOPTION)
            ->where('created_at', '>', now()->subDays(30))
            ->whereHas('league', fn ($query) => $query->whereDoesntHave('matches'))
            ->with('league')
            ->limit(20)
            ->get()
            ->each(fn (Draft $draft) => self::forDraft($draft));
    }

    /**
     * Adopt the match-holding half of this draft's run, if there is one.
     *
     * @return bool Whether an adoption happened.
     */
    public static function forDraft(Draft $draft): bool
    {
        $minted = $draft->league;

        if (! $minted || ! $minted->event_id || $minted->matches()->exists()) {
            return false;
        }

        $candidates = League::query()
            ->where('event_id', $minted->event_id)
            ->whereNull('mtgo_course_id')
            ->whereIn('kind', [LeagueKind::Draft, LeagueKind::Sealed])
            ->whereKeyNot($minted->getKey())
            ->whereDoesntHave('draft')
            ->with('deckSnapshots')
            ->get();

        foreach ($candidates as $candidate) {
            if (self::startedBeforeTheDraft($draft, $candidate)) {
                continue;
            }

            if (! RegisteredDeckMatchesDraftPool::run($draft, $candidate)) {
                continue;
            }

            self::adopt($draft, $minted, $candidate);

            return true;
        }

        return false;
    }

    /**
     * A run's matches are always played after its draft, so a candidate
     * holding a match that started earlier belongs to an earlier run,
     * whatever its deck happens to overlap.
     */
    private static function startedBeforeTheDraft(Draft $draft, League $candidate): bool
    {
        if (! $draft->started_at) {
            return false;
        }

        $earliestMatch = $candidate->matches()->min('started_at');

        return $earliestMatch !== null && Carbon::parse($earliestMatch)->lessThan($draft->started_at);
    }

    /**
     * Move the draft onto the candidate and retire the minted row.
     *
     * Order matters: leagues are unique on (event_id, mtgo_course_id) and a
     * soft delete leaves the row in that index, so the minted row has to give
     * up the CourseID before the candidate can take it. The candidate is the
     * live run again, whatever ResolveDraftLeague concluded about it when it
     * looked like an older Active league for the same event.
     */
    private static function adopt(Draft $draft, League $minted, League $candidate): void
    {
        $courseId = $minted->mtgo_course_id;
        $startedAt = $minted->started_at && $candidate->started_at
            ? min($minted->started_at, $candidate->started_at)
            : ($minted->started_at ?? $candidate->started_at);

        TimedTransaction::run("AdoptCourselessDraftLeague:{$draft->id}", function () use ($draft, $minted, $candidate, $courseId, $startedAt): void {
            $minted->update(['mtgo_course_id' => null, 'state' => LeagueState::Partial]);
            $minted->delete();

            $candidate->fill([
                'mtgo_course_id' => $courseId,
                'set_code' => $candidate->set_code ?? $minted->set_code,
                'started_at' => $startedAt,
                'joined_at' => $candidate->joined_at ?? $minted->joined_at,
                'state' => LeagueState::Active,
                'completed_at' => null,
            ])->save();

            $draft->update(['league_id' => $candidate->id]);
        });

        Log::channel('pipeline')->info("AdoptCourselessDraftLeague: league #{$candidate->id} adopted draft #{$draft->id}", [
            'event_id' => $candidate->event_id,
            'course_id' => $courseId,
            'retired_league_id' => $minted->id,
        ]);
    }
}
