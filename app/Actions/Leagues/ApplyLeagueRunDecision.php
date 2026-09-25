<?php

namespace App\Actions\Leagues;

use App\Enums\LeagueKind;
use App\Enums\LeagueState;
use App\Models\League;
use App\Models\MtgoMatch;
use App\Sidecar\LeagueRunDecision;
use App\Sidecar\LeagueSnapshot;
use App\Support\TimedTransaction;

class ApplyLeagueRunDecision
{
    /**
     * The only writer for sidecar league decisions (spec 5.3). A decision
     * equal to the current state writes nothing.
     *
     * "Mint" reuses the match's current league when that league holds only
     * this match: re-stamping it avoids a second row with the same
     * LeagueClientId (token + started_at). A league the match leaves that is
     * then empty is soft-deleted; it only ever existed for this match (the
     * log path minted it seconds earlier).
     */
    public static function run(MtgoMatch $match, LeagueRunDecision $decision, LeagueSnapshot $snapshot): League
    {
        return TimedTransaction::run('ApplyLeagueRunDecision', function () use ($match, $decision, $snapshot): League {
            foreach ($decision->close as $leagueId => $state) {
                $league = League::find($leagueId);

                if ($league === null) {
                    continue;
                }

                if ($state === LeagueState::Complete) {
                    CompleteLeague::run($league);
                } else {
                    $league->update(['state' => LeagueState::Partial]);
                }
            }

            $previous = $match->league;
            $isNewRow = $decision->mint;

            if ($decision->mint && $previous !== null && ! $previous->manual && $previous->matches()->whereKeyNot($match->id)->doesntExist()) {
                $target = $previous;
            } elseif ($decision->mint) {
                $target = MintLeague::run($match, $snapshot->token, $snapshot->eventId, $match->format, $match->match_type, null);
            } else {
                $target = League::findOrFail($decision->targetLeagueId);
            }

            $attributes = [];

            if ($target->state !== LeagueState::Active && ($decision->reactivate || $target->is($previous))) {
                $attributes['state'] = LeagueState::Active;
            }

            if ($target->event_id === null && $snapshot->eventId !== null) {
                $attributes['event_id'] = $snapshot->eventId;
            }

            // A league without a deck never syncs (DirtyRows joins through
            // deckVersion.deck), so the first match that knows its deck fills
            // it in. Constructed only: limited leagues track their own.
            if ($target->deck_version_id === null && $match->deck_version_id !== null && $target->kind === LeagueKind::Constructed) {
                $attributes['deck_version_id'] = $match->deck_version_id;
            }

            if ($attributes !== []) {
                $target->update($attributes);
            }

            if ($match->league_id !== $target->id) {
                $match->update(['league_id' => $target->id]);
            }

            if ($previous !== null && ! $previous->is($target) && ! $previous->manual && $previous->matches()->doesntExist()) {
                $previous->delete();
            }

            if ($isNewRow && $snapshot->historyMatchIds) {
                MtgoMatch::query()
                    ->whereIn('mtgo_id', $snapshot->historyMatchIds)
                    ->whereNull('league_id')
                    ->where('manual', false)
                    ->whereKeyNot($match->id)
                    ->update(['league_id' => $target->id]);
            }

            ResolveLeagueSetCode::run($target, $match->format);

            return $target;
        });
    }
}
