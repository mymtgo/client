<?php

namespace App\Actions\Leagues;

use App\Enums\LeagueState;
use App\Enums\LogEventType;
use App\Models\League;
use App\Models\LogEvent;
use Illuminate\Support\Facades\Log;

class AttributeDropFromLog
{
    /**
     * Drop signals carry no league token. We attribute the drop to the most
     * recently viewed league panel — the user must navigate to a league's
     * details panel to click "Drop", so the most recent league_joined event
     * (which captures EventToken/EventId from the panel view) preceding the
     * drop identifies the dropped league. This works correctly when multiple
     * leagues are concurrently Active (e.g. Pioneer + Modern).
     *
     * If no panel view precedes the drop, we do nothing — false positives
     * (marking the wrong league Partial) are worse than false negatives.
     */
    public static function run(LogEvent $event): void
    {
        $panelView = LogEvent::where('event_type', LogEventType::LEAGUE_JOINED->value)
            ->where('logged_at', '<=', $event->logged_at)
            ->whereNotNull('match_id')
            ->orderByDesc('logged_at')
            ->first();

        if (! $panelView) {
            Log::channel('pipeline')->warning('ProcessLeagueEvents: drop signal with no preceding panel view, skipping', [
                'dropped_at' => $event->logged_at,
            ]);

            return;
        }

        // Defense in depth: pick the most recently started Active league when
        // multiple share the same event_id (legacy data where AssignLeague
        // created a duplicate before the format-filter fix).
        $league = League::where('event_id', (int) $panelView->match_id)
            ->where('state', LeagueState::Active)
            ->latest('started_at')
            ->first();

        if (! $league) {
            // Fallback: token-only lookup. Covers leagues created by
            // AssignLeague that haven't yet been backfilled with event_id
            // (panel-view event arrived in the same tick as the drop).
            $league = League::where('token', $panelView->match_token)
                ->where('state', LeagueState::Active)
                ->latest('started_at')
                ->first();
        }

        if (! $league) {
            return;
        }

        $league->update([
            'state' => LeagueState::Dropped,
            'dropped_at' => $event->logged_at,
        ]);

        Log::channel('pipeline')->info("ProcessLeagueEvents: marked league #{$league->id} as dropped", [
            'dropped_at' => $event->logged_at,
            'event_id' => $panelView->match_id,
        ]);
    }
}
