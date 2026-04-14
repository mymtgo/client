<?php

namespace App\Actions\Leagues;

use App\Enums\LeagueState;
use App\Models\League;
use App\Models\LogEvent;
use Carbon\Carbon;
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
            self::processJoin($event);
            $event->update(['processed_at' => now()]);
        }

        // Mark join requests as processed after processing league_joined events,
        // since processJoin() queries for recent join requests by logged_at.
        LogEvent::where('event_type', 'league_join_request')
            ->whereNull('processed_at')
            ->update(['processed_at' => now()]);
    }

    private static function processJoin(LogEvent $event): void
    {
        $leagueToken = $event->match_token;
        $eventId = (int) $event->match_id;

        // Extract format from raw_text
        $format = 'Unknown';
        if (preg_match('/PlayFormatCd=(\S+)/', $event->raw_text, $m)) {
            $format = $m[1];
        }

        // Require a FlsLeagueUserJoinReqMessage within 10 seconds of this event.
        // On first join, the request comes BEFORE the GameDetailsView.
        // On re-entry, the Join Event fires BEFORE the request.
        // So we check both directions within the window.
        $eventTime = Carbon::parse($event->logged_at);
        $hasJoinRequest = LogEvent::where('event_type', 'league_join_request')
            ->where('logged_at', '>=', $eventTime->copy()->subSeconds(10))
            ->where('logged_at', '<=', $eventTime->copy()->addSeconds(10))
            ->exists();

        // Check for an existing active league with this token.
        // Only Active leagues matter — Partial (dropped) and Complete (finished)
        // runs should not be modified or reused for new matches.
        $existingLeague = League::where('token', $leagueToken)
            ->where('state', LeagueState::Active)
            ->latest('started_at')
            ->first();

        if ($existingLeague) {
            // Backfill event_id on reactive leagues (created by AssignLeague without it)
            if (! $existingLeague->event_id) {
                $existingLeague->update(['event_id' => $eventId, 'joined_at' => $existingLeague->joined_at ?? $event->logged_at]);
                Log::channel('pipeline')->info("ProcessLeagueEvents: backfilled event_id={$eventId} on league #{$existingLeague->id}");
            }

            // No join request — just a UI re-display, nothing more to do
            if (! $hasJoinRequest) {
                return;
            }

            // Has join request + existing league with matches = genuine re-entry
            if ($existingLeague->matches()->count() > 0) {
                $existingLeague->update(['state' => LeagueState::Partial]);

                Log::channel('pipeline')->info("ProcessLeagueEvents: marked league #{$existingLeague->id} as partial (re-entry detected)", [
                    'event_id' => $eventId,
                    'old_matches' => $existingLeague->matches()->count(),
                ]);
            } else {
                // Empty league + new join request — reuse it
                Log::channel('pipeline')->info("ProcessLeagueEvents: reusing empty league #{$existingLeague->id} for event_id={$eventId}");

                return;
            }
        } elseif (! $hasJoinRequest) {
            // No existing league and no join request — just a UI view, ignore
            return;
        }

        // Create new league row
        $league = League::create([
            'token' => $leagueToken,
            'event_id' => $eventId,
            'format' => $format,
            'phantom' => false,
            'state' => LeagueState::Active,
            'started_at' => $event->logged_at,
            'joined_at' => $event->logged_at,
            'name' => 'League '.Carbon::parse($event->logged_at)->toLocal()->format('d-m-Y h:ma'),
        ]);

        Log::channel('pipeline')->info("ProcessLeagueEvents: created league #{$league->id}", [
            'event_id' => $eventId,
            'token' => $leagueToken,
            'format' => $format,
        ]);
    }
}
