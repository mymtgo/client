<?php

namespace App\Actions\Leagues;

use App\Enums\LeagueState;
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
            AttributeDropFromLog::run($event);
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
}
