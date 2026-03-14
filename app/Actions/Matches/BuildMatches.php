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
        $username = Account::current()->value('username');

        if (! $username) {
            Log::debug('BuildMatches: no current account username, aborting');

            return;
        }

        Mtgo::setUsername($username);

        $account = Account::where('username', $username)->first();

        if ($account && ! $account->tracked) {
            Log::debug("BuildMatches: account {$username} is not tracked, aborting");

            return;
        }

        // 1. New match detection — find unprocessed match tokens
        $matchTokens = LogEvent::whereNotNull('match_id')
            ->whereNotNull('match_token')
            ->whereNull('processed_at')
            ->distinct()
            ->pluck('match_token');

        $matchIds = LogEvent::whereIn('match_token', $matchTokens)
            ->whereNotNull('match_id')
            ->distinct()
            ->pluck('match_id', 'match_token');

        Log::debug("BuildMatches: found {$matchTokens->count()} unprocessed tokens, {$matchIds->count()} new match IDs");

        foreach ($matchIds as $matchToken => $matchId) {
            if (MtgoMatch::where('mtgo_id', $matchId)->exists()) {
                continue;
            }

            Log::debug("BuildMatches: creating match token={$matchToken} id={$matchId}");
            $result = AdvanceMatchState::run($matchToken, $matchId);
            Log::debug('BuildMatches: AdvanceMatchState returned '.($result ? "match #{$result->id} state={$result->state->value}" : 'null'));
        }

        // 2. State advancement — advance all incomplete matches
        $incompleteMatches = MtgoMatch::incomplete()->get();

        foreach ($incompleteMatches as $match) {
            AdvanceMatchState::run($match->token, $match->mtgo_id);
        }

        // 3. Stale match resolution — void or end matches that can't complete
        ResolveStaleMatches::run();
    }
}
