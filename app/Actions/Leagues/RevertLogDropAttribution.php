<?php

namespace App\Actions\Leagues;

use App\Actions\Sidecar\AwaitSidecarAnswer;
use App\Actions\Sidecar\RecordFieldDiff;
use App\Enums\LeagueState;
use App\Models\GameEvent;
use App\Models\League;
use App\Models\LogEvent;
use App\Sidecar\Resolution;

class RevertLogDropAttribution
{
    /**
     * A log drop that already ran inside the sidecar leave's window may
     * have credited the drop to the wrong league (the panel-view heuristic
     * in AttributeDropFromLog). The league it marked is identified by
     * dropped_at equal to that log event's logged_at; put it back to Active
     * and record the disagreement against its latest match.
     */
    public static function run(GameEvent $leftEvent, League $dropped): void
    {
        $window = AwaitSidecarAnswer::WINDOW_SECONDS;

        $logDrops = LogEvent::query()
            ->where('event_type', 'league_dropped')
            ->whereNotNull('processed_at')
            ->whereBetween('created_at', [$leftEvent->created_at->copy()->subSeconds($window), $leftEvent->created_at->copy()->addSeconds($window)])
            ->get();

        foreach ($logDrops as $logDrop) {
            $wrong = League::query()
                ->where('state', LeagueState::Dropped)
                ->where('dropped_at', $logDrop->logged_at)
                ->whereKeyNot($dropped->id)
                ->first();

            if ($wrong === null) {
                continue;
            }

            $wrong->update(['state' => LeagueState::Active, 'dropped_at' => null]);

            if ($match = $wrong->matches()->latest('started_at')->first()) {
                RecordFieldDiff::run($match, null, 'league_drop', new Resolution($dropped->id, 'sidecar', true), $wrong->id, $dropped->id);
            }
        }
    }
}
