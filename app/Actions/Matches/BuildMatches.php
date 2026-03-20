<?php

namespace App\Actions\Matches;

use App\Facades\Mtgo;
use App\Models\Account;
use App\Models\LogEvent;
use Illuminate\Support\Facades\Log;

/**
 * @deprecated Superseded by the event-driven pipeline (DispatchDomainEvents + listeners).
 *             Retained for manual debug workflows and legacy compatibility.
 */
class BuildMatches
{
    public static function run()
    {
        // Find all match tokens with unprocessed events (excluding league events)
        $tokensWithWork = LogEvent::whereNotNull('match_id')
            ->whereNotNull('match_token')
            ->whereNull('processed_at')
            ->where('event_type', '!=', 'league_joined')
            ->where('event_type', '!=', 'league_join_request')
            ->distinct()
            ->pluck('match_id', 'match_token');

        if ($tokensWithWork->isNotEmpty()) {
            Log::channel('pipeline')->info("BuildMatches: {$tokensWithWork->count()} tokens with unprocessed events");
        }

        // Advance each match that has new events — whether new or existing
        foreach ($tokensWithWork as $matchToken => $matchId) {
            $username = LogEvent::where('match_token', $matchToken)
                ->whereNotNull('username')
                ->value('username');

            if (! $username) {
                self::handleMissingUsername($matchToken);

                continue;
            }

            $account = Account::where('username', $username)->first();

            if ($account && ! $account->tracked) {
                Log::channel('pipeline')->info("BuildMatches: account {$username} is not tracked, skipping token={$matchToken}");

                continue;
            }

            Mtgo::setUsername($username);

            $result = AdvanceMatchState::run($matchToken, $matchId);

            // If AdvanceMatchState returned null (no join event yet), mark
            // stale events as processed to avoid retrying forever
            if (! $result) {
                self::handleMissingJoinEvent($matchToken);
            }
        }

        // Resolve stale matches — incomplete matches with no recent events
        ResolveStaleMatches::run();
    }

    private static function handleMissingUsername(string $matchToken): void
    {
        self::markStaleEventsProcessed($matchToken, 'no username');
    }

    private static function handleMissingJoinEvent(string $matchToken): void
    {
        self::markStaleEventsProcessed($matchToken, 'no join event');
    }

    /**
     * Mark events older than 2 minutes as processed to avoid retrying forever.
     * Fresh events get a grace window for out-of-order delivery.
     */
    private static function markStaleEventsProcessed(string $matchToken, string $reason): void
    {
        $stale = LogEvent::where('match_token', $matchToken)
            ->whereNull('processed_at')
            ->where('ingested_at', '<', now()->subMinutes(2))
            ->exists();

        if ($stale) {
            LogEvent::where('match_token', $matchToken)
                ->whereNull('processed_at')
                ->update(['processed_at' => now()]);

            Log::channel('pipeline')->info("BuildMatches: marked stale events processed for token={$matchToken} ({$reason} after 2 min)");
        }
    }
}
