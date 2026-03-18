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
            self::processJoin($event);
            $event->update(['processed_at' => now()]);
        }
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

        // Check for existing active league with this token
        $existingLeague = League::where('token', $leagueToken)
            ->where('state', LeagueState::Active)
            ->latest('started_at')
            ->first();

        if ($existingLeague) {
            // If the existing league has no matches, it's likely the same join
            // being re-processed (idempotent) — reuse it
            if ($existingLeague->matches()->count() === 0) {
                Log::channel('pipeline')->info("ProcessLeagueEvents: reusing empty active league #{$existingLeague->id} for event_id={$eventId}");

                return;
            }

            // Existing league has matches — this is a re-entry. Mark old as partial.
            $existingLeague->update(['state' => LeagueState::Partial]);

            Log::channel('pipeline')->info("ProcessLeagueEvents: marked league #{$existingLeague->id} as partial (re-entry detected)", [
                'event_id' => $eventId,
                'old_matches' => $existingLeague->matches()->count(),
            ]);
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
            'name' => 'League '.now()->parse($event->logged_at)->format('d-m-Y h:ma'),
        ]);

        Log::channel('pipeline')->info("ProcessLeagueEvents: created league #{$league->id}", [
            'event_id' => $eventId,
            'token' => $leagueToken,
            'format' => $format,
        ]);
    }
}
