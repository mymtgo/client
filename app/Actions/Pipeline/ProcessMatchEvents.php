<?php

namespace App\Actions\Pipeline;

use App\Actions\Matches\AdvanceMatchState;
use App\Enums\MatchState;
use App\Facades\Mtgo;
use App\Models\Account;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessMatchEvents
{
    /**
     * Process all matches that have unprocessed log events.
     *
     * @return array<string> Tokens that were processed (for the second loop to skip)
     */
    public static function run(): array
    {
        $processedTokens = [];

        $tokensWithWork = LogEvent::whereNotNull('match_id')
            ->whereNotNull('match_token')
            ->whereNull('processed_at')
            ->where('event_type', '!=', 'league_joined')
            ->where('event_type', '!=', 'league_join_request')
            ->distinct()
            ->pluck('match_id', 'match_token');

        foreach ($tokensWithWork as $matchToken => $matchId) {
            static::processMatch($matchToken, $matchId);
            $processedTokens[] = $matchToken;
        }

        return $processedTokens;
    }

    private static function processMatch(string $matchToken, int|string $matchId): void
    {
        $existingMatch = MtgoMatch::where('token', $matchToken)->first();
        if ($existingMatch?->failed_at !== null) {
            return;
        }

        // Username resolution (from BuildMatches pattern)
        $username = LogEvent::where('match_token', $matchToken)
            ->whereNotNull('username')
            ->value('username');

        if (! $username) {
            static::handleMissingUsername($matchToken);

            return;
        }

        $account = Account::where('username', $username)->first();

        if ($account && ! $account->tracked) {
            static::markEventsProcessed($matchToken);

            return;
        }

        Mtgo::setUsername($username);

        try {
            DB::transaction(function () use ($matchToken, $matchId) {
                $match = AdvanceMatchState::run($matchToken, $matchId);

                if (! $match) {
                    static::markStaleEventsProcessed($matchToken);

                    return;
                }

                // Check game log for results inline — non-fatal, next tick retries
                if (in_array($match->state, [MatchState::InProgress, MatchState::Ended])) {
                    try {
                        ResolveGameResults::run($match);
                    } catch (\Throwable $e) {
                        Log::channel('pipeline')->warning("Match {$match->mtgo_id}: game log resolution failed, will retry", [
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                // Mark all events for this match token as processed
                static::markEventsProcessed($matchToken);
            });
        } catch (\Throwable $e) {
            $match = $existingMatch ?? MtgoMatch::where('token', $matchToken)->first();

            if ($match) {
                static::handleMatchFailure($match, $e);
            } else {
                Log::channel('pipeline')->error("ProcessMatches: exception for token={$matchToken} (no match record)", [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private static function handleMatchFailure(MtgoMatch $match, \Throwable $e): void
    {
        $attempts = $match->attempts + 1;
        $updates = ['attempts' => $attempts];

        if ($attempts >= 5) {
            $updates['failed_at'] = now();
            Log::channel('pipeline')->error("Match {$match->mtgo_id}: permanently failed after {$attempts} attempts", [
                'error' => $e->getMessage(),
            ]);
        } else {
            Log::channel('pipeline')->warning("Match {$match->mtgo_id}: attempt {$attempts}/5 failed", [
                'error' => $e->getMessage(),
            ]);
        }

        // Update outside the rolled-back transaction
        $match->update($updates);
    }

    private static function markEventsProcessed(string $matchToken): void
    {
        LogEvent::where('match_token', $matchToken)
            ->whereNull('processed_at')
            ->update(['processed_at' => now()]);
    }

    private static function handleMissingUsername(string $matchToken): void
    {
        $stale = LogEvent::where('match_token', $matchToken)
            ->whereNull('processed_at')
            ->where('ingested_at', '<', now()->subMinutes(2))
            ->exists();

        if ($stale) {
            static::markEventsProcessed($matchToken);
            Log::channel('pipeline')->info("ProcessMatches: marked stale events processed for token={$matchToken} (no username after 2 min)");
        }
    }

    private static function markStaleEventsProcessed(string $matchToken): void
    {
        $stale = LogEvent::where('match_token', $matchToken)
            ->whereNull('processed_at')
            ->where('ingested_at', '<', now()->subMinutes(2))
            ->exists();

        if ($stale) {
            static::markEventsProcessed($matchToken);
            Log::channel('pipeline')->info("ProcessMatches: marked stale events processed for token={$matchToken} (no join event after 2 min)");
        }
    }
}
