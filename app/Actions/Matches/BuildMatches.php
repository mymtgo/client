<?php

namespace App\Actions\Matches;

use App\Facades\Mtgo;
use App\Models\Account;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use Illuminate\Support\Facades\Log;

class BuildMatches
{
    public static function run()
    {
        // 1. New match detection — find unprocessed match tokens
        //    Exclude league_joined events — they reuse match_token/match_id
        //    for EventToken/EventId and are handled by ProcessLeagueEvents.
        $matchTokens = LogEvent::whereNotNull('match_id')
            ->whereNotNull('match_token')
            ->whereNull('processed_at')
            ->where('event_type', '!=', 'league_joined')
            ->distinct()
            ->pluck('match_token');

        $matchIds = LogEvent::whereIn('match_token', $matchTokens)
            ->whereNotNull('match_id')
            ->distinct()
            ->pluck('match_id', 'match_token');

        Log::channel('pipeline')->info("BuildMatches: found {$matchTokens->count()} unprocessed tokens, {$matchIds->count()} new match IDs");

        foreach ($matchIds as $matchToken => $matchId) {
            if (MtgoMatch::where('mtgo_id', $matchId)->exists()) {
                continue;
            }

            $username = LogEvent::where('match_token', $matchToken)
                ->whereNotNull('username')
                ->value('username');

            if (! $username) {
                Log::channel('pipeline')->info("BuildMatches: no username on events for token={$matchToken}, skipping");

                continue;
            }

            $account = Account::where('username', $username)->first();

            if ($account && ! $account->tracked) {
                Log::channel('pipeline')->info("BuildMatches: account {$username} is not tracked, skipping token={$matchToken}");

                continue;
            }

            Mtgo::setUsername($username);

            // Pre-check: does a join event exist for this token? If not,
            // AdvanceMatchState will return null — skip to avoid wasted work.
            $hasJoinEvent = LogEvent::where('match_token', $matchToken)
                ->where(function ($q) {
                    $q->where('context', 'like', '%MatchJoinedEventUnderwayState%')
                        ->orWhere('raw_text', 'like', '%MatchJoinedEventUnderwayState%');
                })
                ->exists();

            if (! $hasJoinEvent) {
                // Mark events older than 2 minutes as processed to break the
                // retry loop. Fresh events get a grace window for out-of-order
                // delivery. AdvanceMatchState queries by match_id regardless of
                // processed_at, so it will still see them when the join arrives.
                $stale = LogEvent::where('match_token', $matchToken)
                    ->whereNull('processed_at')
                    ->where('ingested_at', '<', now()->subMinutes(2))
                    ->exists();

                if ($stale) {
                    LogEvent::where('match_token', $matchToken)
                        ->whereNull('processed_at')
                        ->update(['processed_at' => now()]);

                    Log::channel('pipeline')->info("BuildMatches: marked stale events processed for token={$matchToken} (no join event after 2 min)");
                }

                continue;
            }

            Log::channel('pipeline')->info("BuildMatches: creating match token={$matchToken} id={$matchId} username={$username}");
            $result = AdvanceMatchState::run($matchToken, $matchId);
            Log::channel('pipeline')->info('BuildMatches: AdvanceMatchState returned '.($result ? "match #{$result->id} state={$result->state->value}" : 'null'));
        }

        // 2. State advancement — advance all incomplete matches
        $incompleteMatches = MtgoMatch::incomplete()->get();

        foreach ($incompleteMatches as $match) {
            $username = LogEvent::where('match_token', $match->token)
                ->whereNotNull('username')
                ->value('username');

            if ($username) {
                Mtgo::setUsername($username);
            }

            AdvanceMatchState::run($match->token, $match->mtgo_id);
        }

        // 3. Stale match resolution — void or end matches that can't complete
        ResolveStaleMatches::run();
    }
}
