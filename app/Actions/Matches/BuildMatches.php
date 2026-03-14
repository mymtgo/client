<?php

namespace App\Actions\Matches;

use App\Facades\Mtgo;
use App\Models\Account;
use App\Models\LogEvent;
use App\Models\MtgoMatch;

class BuildMatches
{
    public static function run()
    {
        $username = Account::current()->value('username');

        if (! $username) {
            return;
        }

        Mtgo::setUsername($username);

        $account = Account::where('username', $username)->first();

        if ($account && ! $account->tracked) {
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

        foreach ($matchIds as $matchToken => $matchId) {
            if (MtgoMatch::where('mtgo_id', $matchId)->exists()) {
                continue;
            }

            AdvanceMatchState::run($matchToken, $matchId);
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
