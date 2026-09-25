<?php

namespace App\Actions\Leagues;

use App\Actions\Drafts\ResolveDraftLeague;
use App\Enums\LeagueKind;
use App\Enums\LeagueState;
use App\Models\League;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use Illuminate\Support\Facades\Log;

class HealPlaceholderLeague
{
    /**
     * 2.9. Heal a placeholder-token limited league. ResolveDraftLeague
     * mints "draft-{leagueId}-{courseId}" when the draft lines
     * arrived before any league_joined panel view, so steps 2 and
     * 2.2 (which look up the real League Token) cannot find the run
     * that owns this match and step 3 would split it permanently.
     * The panel view is what finally ties the real token to the
     * LeagueID, so adopt the placeholder run and stamp the real
     * token on it. The pool guard applies here too: without it a
     * match from a later, unwatched re-entry would glue onto the
     * previous run purely because that run's token was a
     * placeholder.
     */
    public static function run(MtgoMatch $match, string $token, LogEvent $panelView): ?League
    {
        $placeholder = League::query()
            ->where('event_id', (int) $panelView->match_id)
            ->where('state', LeagueState::Active)
            ->whereIn('kind', [LeagueKind::Draft, LeagueKind::Sealed])
            ->where('token', 'like', ResolveDraftLeague::PLACEHOLDER_PREFIX.'%')
            ->latest('started_at')
            ->first();

        if ($placeholder && ! LeaguePoolRejectsMatch::run($placeholder, $match)) {
            $placeholder->update(['token' => $token]);

            Log::channel('pipeline')->info("Match {$match->mtgo_id}: healed placeholder token on league #{$placeholder->id}", [
                'token' => $token,
            ]);

            return $placeholder;
        }

        return null;
    }
}
