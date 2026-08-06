<?php

namespace App\Actions\Matches;

use App\Actions\Pipeline\ProcessMatchEvents;
use App\Models\LogEvent;
use App\Models\MtgoMatch;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReprocessMatch
{
    /**
     * Rebuild a match from its log events: purge the match and all derived
     * data (keeping log events intact), reset the events to unprocessed and
     * re-run projection for the token so the match is recreated from scratch.
     *
     * Projection runs synchronously and reads only log_events rows — it does
     * NOT go through RunPipeline, whose pathsAreValid() gate would silently
     * skip the rebuild on machines without an MTGO install (dev machines).
     *
     * The match row is force-deleted without firing model events —
     * MtgoMatchObserver::deleting would otherwise delete the log events the
     * rebuild depends on.
     *
     * @return bool False when the match has no log events to rebuild from
     *              (e.g. an imported match); nothing is deleted in that case.
     */
    public static function run(MtgoMatch $match): bool
    {
        $gameMtgoIds = $match->games()->pluck('mtgo_id');

        if (! self::eventsQuery($match, $gameMtgoIds)->exists()) {
            return false;
        }

        DB::transaction(function () use ($match, $gameMtgoIds) {
            PurgeMatchDerivedData::run($match, includeLogEvents: false);

            MtgoMatch::withoutEvents(fn () => $match->forceDelete());

            self::eventsQuery($match, $gameMtgoIds)->update(['processed_at' => null]);
        });

        ProcessMatchEvents::runForToken($match->token, $match->mtgo_id);

        return true;
    }

    /**
     * All log events belonging to the match, matched the same way
     * MtgoMatchObserver::deleting scopes its log event cleanup.
     *
     * @param  Collection<int, int|string>  $gameMtgoIds
     */
    private static function eventsQuery(MtgoMatch $match, Collection $gameMtgoIds): Builder
    {
        return LogEvent::where(function ($q) use ($match, $gameMtgoIds) {
            $q->where('match_id', $match->mtgo_id)
                ->orWhere('match_token', $match->token);

            if ($gameMtgoIds->isNotEmpty()) {
                $q->orWhereIn('game_id', $gameMtgoIds);
            }
        });
    }
}
