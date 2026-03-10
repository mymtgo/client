<?php

namespace App\Actions\Matches;

use App\Enums\MatchState;
use App\Events\AppNotification;
use App\Models\MtgoMatch;

class ResolveStaleMatches
{
    /**
     * Detect and resolve matches stuck in Started/InProgress/Ended.
     *
     * A match is stale if a newer match has been created since it started.
     * We give AdvanceMatchState one final pass, then void or end the match
     * depending on whether it belongs to a real league.
     */
    public static function run(): void
    {
        $incompleteMatches = MtgoMatch::incomplete()
            ->orderBy('started_at')
            ->get();

        if ($incompleteMatches->isEmpty()) {
            return;
        }

        $latestMatchStart = MtgoMatch::latest('started_at')->value('started_at');

        foreach ($incompleteMatches as $match) {
            // A match is stale if a newer match exists after it
            $newerMatchExists = MtgoMatch::where('id', '!=', $match->id)
                ->where('started_at', '>', $match->started_at)
                ->exists();

            if (! $newerMatchExists) {
                continue;
            }

            // Give AdvanceMatchState one final attempt
            AdvanceMatchState::run($match->token, $match->mtgo_id);
            $match->refresh();

            // If it completed, we're done
            if ($match->state === MatchState::Complete) {
                continue;
            }

            // Determine if this is a real league match (non-phantom, has league)
            $isRealLeague = $match->league_id && ! $match->league?->phantom;

            if ($isRealLeague) {
                // Real league: mark as Ended so it's visible but indicates incomplete
                $match->update(['state' => MatchState::Ended]);

                AppNotification::dispatch(
                    type: 'match_incomplete',
                    title: 'Match recorded but some data is missing',
                    message: '',
                    route: '/matches/'.$match->id,
                );
            } else {
                // Casual: void it completely
                $match->update(['state' => MatchState::Voided]);

                AppNotification::dispatch(
                    type: 'match_voided',
                    title: 'Unable to determine match results',
                    message: '',
                );
            }
        }
    }
}
