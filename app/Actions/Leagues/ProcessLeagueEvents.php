<?php

namespace App\Actions\Leagues;

use App\Enums\LeagueState;
use App\Enums\LogEventType;
use App\Models\League;
use App\Models\LogEvent;
use Illuminate\Support\Facades\Log;

class ProcessLeagueEvents
{
    public static function run(): void
    {
        $joinEvents = LogEvent::where('event_type', 'league_joined')
            ->whereNull('processed_at')
            ->orderBy('timestamp')
            ->get();

        foreach ($joinEvents as $event) {
            self::backfillFromPanelView($event);
            $event->update(['processed_at' => now()]);
        }

        $dropEvents = LogEvent::where('event_type', 'league_dropped')
            ->whereNull('processed_at')
            ->orderBy('timestamp')
            ->get();

        foreach ($dropEvents as $event) {
            self::processDrop($event);
            $event->update(['processed_at' => now()]);
        }

        LogEvent::where('event_type', 'league_join_request')
            ->whereNull('processed_at')
            ->update(['processed_at' => now()]);
    }

    /**
     * Stamp event_id and joined_at on an Active league created reactively by
     * AssignLeague. Leagues are only created when a match arrives — so deck
     * version is always known. Panel-view events provide the missing event_id
     * (which match logs do not expose in a parseable form) and the real
     * join time (closer to the user's actual join click than first-match time).
     *
     * Never creates a league. If no Active league with this token exists yet,
     * the panel view is informational only — a future match will trigger
     * creation, and a later panel view (or this one re-processed) will backfill.
     */
    private static function backfillFromPanelView(LogEvent $event): void
    {
        $league = League::where('token', $event->match_token)
            ->where('state', LeagueState::Active)
            ->whereNull('event_id')
            ->latest('started_at')
            ->first();

        if (! $league) {
            return;
        }

        $league->update([
            'event_id' => (int) $event->match_id,
            'joined_at' => $league->joined_at ?? $event->logged_at,
        ]);

        Log::channel('pipeline')->info("ProcessLeagueEvents: backfilled event_id={$event->match_id} on league #{$league->id}");
    }

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
    private static function processDrop(LogEvent $event): void
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
