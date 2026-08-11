<?php

namespace App\Actions\Matches;

use App\Actions\Pipeline\ProcessMatchEvents;
use App\Models\LogEvent;
use App\Models\MatchArchetype;
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
     * Hand-picked opponent archetypes (`match_archetypes.manual`) are carried
     * across the rebuild — see restoreManualArchetypes().
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

        $manualArchetypes = self::manualArchetypes($match);

        DB::transaction(function () use ($match, $gameMtgoIds) {
            PurgeMatchDerivedData::run($match, includeLogEvents: false);

            MtgoMatch::withoutEvents(fn () => $match->forceDelete());

            self::eventsQuery($match, $gameMtgoIds)->update(['processed_at' => null]);
        });

        ProcessMatchEvents::runForToken($match->token, $match->mtgo_id);

        self::restoreManualArchetypes($match->token, $manualArchetypes);

        return true;
    }

    /**
     * The match's hand-picked archetype rows, captured before the purge.
     *
     * @return Collection<int, \stdClass>
     */
    private static function manualArchetypes(MtgoMatch $match): Collection
    {
        return DB::table('match_archetypes')
            ->where('mtgo_match_id', $match->id)
            ->where('manual', true)
            ->get(['player_id', 'archetype_id', 'archetype_deck_id', 'confidence']);
    }

    /**
     * Re-attach hand-picked archetypes to the rebuilt match.
     *
     * They cannot simply be left in place by the purge: the match row is
     * force-deleted, so its `mtgo_match_id` foreign key would block the delete,
     * and the rebuild inserts a fresh row with a new autoincrement id. The rows
     * are re-keyed onto the rebuilt match instead, matched on `player_id` —
     * reprocessing never deletes players. `updateOrCreate` semantics overwrite
     * whatever end-of-match detection guessed for that player during the
     * rebuild, which is the point: a manual pick outranks detection.
     *
     * @param  Collection<int, \stdClass>  $manualArchetypes
     */
    private static function restoreManualArchetypes(string $token, Collection $manualArchetypes): void
    {
        if ($manualArchetypes->isEmpty()) {
            return;
        }

        $rebuilt = MtgoMatch::where('token', $token)->first();

        if (! $rebuilt) {
            return;
        }

        foreach ($manualArchetypes as $row) {
            MatchArchetype::updateOrCreate(
                [
                    'mtgo_match_id' => $rebuilt->id,
                    'player_id' => $row->player_id,
                ],
                [
                    'archetype_id' => $row->archetype_id,
                    'archetype_deck_id' => $row->archetype_deck_id,
                    'confidence' => $row->confidence,
                    'manual' => true,
                ]
            );
        }
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
