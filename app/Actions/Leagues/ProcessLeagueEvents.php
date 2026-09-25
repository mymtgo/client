<?php

namespace App\Actions\Leagues;

use App\Actions\Sidecar\AwaitSidecarAnswer;
use App\Enums\LeagueState;
use App\Models\GameEvent;
use App\Models\League;
use App\Models\LogEvent;
use App\Sidecar\LeagueSnapshot;
use App\Sidecar\SidecarAuthorityFlags;
use App\Sidecar\SidecarPaths;
use App\Sidecar\SidecarTables;
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

        self::processSidecarLeagueEvents();

        $dropEvents = LogEvent::where('event_type', 'league_dropped')
            ->whereNull('processed_at')
            ->orderBy('timestamp')
            ->get();

        foreach ($dropEvents as $event) {
            if (AwaitSidecarAnswer::run('league_drop', $event->created_at)) {
                continue;
            }

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

    /**
     * Sidecar league membership events (spec 5.4), applied only with the
     * league_drop flag on and a verified event. Joins are recorded and never
     * acted on: a join says nothing about any other league the user runs.
     *
     * A leave with matches remaining only drops a league when the log also
     * shows the user dropping (a league_dropped line in the window). MTGO's
     * EventRemoved hook also fires when the client logs out, closes or loses
     * its connection; without that pairing each of those would drop every
     * running league. The sidecar's job here is naming the right league, not
     * deciding that a drop happened. A leave that arrives before its log
     * line waits (unprocessed) for the rest of the window.
     *
     * One bad event is logged and marked processed; it never stalls the tick.
     */
    private static function processSidecarLeagueEvents(): void
    {
        // Directory check first, mirroring IngestSidecarEvents: a machine with
        // no sidecar at all must still add zero queries per tick.
        if (! is_dir(SidecarPaths::directory()) || ! SidecarTables::ready()) {
            return;
        }

        $applyLeaves = SidecarAuthorityFlags::isOn('league_drop');

        GameEvent::query()
            ->whereIn('type', ['league_left', 'league_joined'])
            ->whereNull('processed_at')
            ->orderBy('session_started_at')
            ->orderBy('seq')
            ->get()
            ->each(function (GameEvent $event) use ($applyLeaves): void {
                try {
                    if ($applyLeaves && $event->type === 'league_left' && $event->verified && ! self::applyLeave($event)) {
                        return;
                    }
                } catch (\Throwable $e) {
                    Log::channel('pipeline')->warning('ProcessLeagueEvents: sidecar league event failed', [
                        'game_event_id' => $event->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                $event->update(['processed_at' => now()]);
            });
    }

    /**
     * @return bool false to leave the event unprocessed for a later tick
     */
    private static function applyLeave(GameEvent $event): bool
    {
        $window = [
            $event->created_at->copy()->subSeconds(AwaitSidecarAnswer::WINDOW_SECONDS),
            $event->created_at->copy()->addSeconds(AwaitSidecarAnswer::WINDOW_SECONDS),
        ];

        $snapshot = LeagueSnapshot::fromArray($event->data['league'] ?? null);
        $isDrop = ($snapshot?->matchesRemaining ?? 0) > 0;
        $logDropSeen = LogEvent::query()->where('event_type', 'league_dropped')->whereBetween('created_at', $window)->exists();

        if ($isDrop && ! $logDropSeen) {
            return $event->created_at->lt(now()->subSeconds(AwaitSidecarAnswer::WINDOW_SECONDS));
        }

        $league = ApplySidecarLeagueLeft::run($event);

        if ($league !== null) {
            LogEvent::query()
                ->where('event_type', 'league_dropped')
                ->whereNull('processed_at')
                ->whereBetween('created_at', $window)
                ->update(['processed_at' => now()]);

            RevertLogDropAttribution::run($event, $league);
        }

        return true;
    }
}
