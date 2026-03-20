<?php

namespace App\Actions\Pipeline;

use App\Events\CardRevealed;
use App\Events\DeckUsedInGame;
use App\Events\GameResultDetermined;
use App\Events\GameStateChanged;
use App\Events\LeagueJoined;
use App\Events\LeagueJoinRequested;
use App\Events\MatchEnded;
use App\Events\MatchJoined;
use App\Events\MatchMetadataReceived;
use App\Events\UserLoggedIn;
use App\Models\LogEvent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class DispatchDomainEvents
{
    /** Match-end signal substrings in the context field */
    private const MATCH_END_SIGNALS = [
        'TournamentMatchClosedState',
        'MatchCompletedState',
        'MatchEndedState',
        'MatchClosedState',
        'JoinedCompletedState',
    ];

    private const MATCH_JOIN_SIGNALS = [
        'MatchJoinedEventUnderwayState',
    ];

    /**
     * Route each LogEvent to its appropriate domain event.
     *
     * @param  Collection<int, LogEvent>  $events
     */
    public static function run(Collection $events): void
    {
        foreach ($events as $event) {
            try {
                match ($event->event_type) {
                    'match_state_changed' => self::dispatchMatchStateEvent($event),
                    'game_state_update' => GameStateChanged::dispatch($event),
                    'game_result' => GameResultDetermined::dispatch($event),
                    'deck_used' => DeckUsedInGame::dispatch($event),
                    'card_revealed' => CardRevealed::dispatch($event),
                    'league_joined' => LeagueJoined::dispatch($event),
                    'league_join_request' => LeagueJoinRequested::dispatch($event),
                    'game_management_json' => MatchMetadataReceived::dispatch($event),
                    default => self::dispatchSpecialEvent($event),
                };
            } catch (\Throwable $e) {
                Log::channel('pipeline')->error("DispatchDomainEvents: failed to dispatch {$event->event_type}", [
                    'event_id' => $event->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private static function dispatchMatchStateEvent(LogEvent $event): void
    {
        $context = $event->context ?? '';

        foreach (self::MATCH_JOIN_SIGNALS as $signal) {
            if (str_contains($context, $signal)) {
                MatchJoined::dispatch($event);

                return;
            }
        }

        // Check for concede pattern (local player conceded and left)
        if (preg_match('/ConcedeReqState to .+NotJoined/', $context)) {
            MatchEnded::dispatch($event);

            return;
        }

        foreach (self::MATCH_END_SIGNALS as $signal) {
            if (str_contains($context, $signal)) {
                MatchEnded::dispatch($event);

                return;
            }
        }
    }

    /**
     * Handle events that don't have a standard event_type but are
     * still meaningful (e.g., login events stored with null event_type).
     */
    private static function dispatchSpecialEvent(LogEvent $event): void
    {
        if ($event->category === 'Login' && $event->context === 'MtGO Login Success') {
            UserLoggedIn::dispatch($event);
        }
    }
}
