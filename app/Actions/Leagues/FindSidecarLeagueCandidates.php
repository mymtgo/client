<?php

namespace App\Actions\Leagues;

use App\Enums\LeagueKind;
use App\Enums\LeagueState;
use App\Enums\MatchState;
use App\Models\League;
use App\Sidecar\LeagueSnapshot;
use Illuminate\Support\Collection;

class FindSidecarLeagueCandidates
{
    /**
     * Leagues a sidecar snapshot can speak for: the snapshot's own league
     * by event_id, or a legacy league with no event_id and the same token
     * (minted before a panel view gave it one). The token is per season and
     * format, so a concurrent league in another format is never returned.
     * Manual and limited leagues are out of sidecar scope (spec section 2).
     * When the snapshot has a token, candidates must carry it: event_id
     * alone can repeat across seasons.
     *
     * Only matches that got past Started and did not fail count as a
     * league's matches: a ghost row that never played must not make the
     * real run look like a different one.
     *
     * Ordered event_id matches first, then newest first.
     *
     * @param  list<LeagueState>  $states
     * @return Collection<int, League>
     */
    public static function run(LeagueSnapshot $snapshot, array $states): Collection
    {
        if ($snapshot->eventId === null && $snapshot->token === null) {
            return collect();
        }

        return League::query()
            ->whereIn('state', $states)
            ->where('manual', false)
            ->where('kind', LeagueKind::Constructed)
            ->when($snapshot->token !== null, fn ($q) => $q->where('token', $snapshot->token))
            ->where(fn ($query) => $query
                ->when($snapshot->eventId !== null, fn ($q) => $q->where('event_id', $snapshot->eventId))
                ->orWhereNull('event_id'))
            ->with(['matches' => fn ($q) => $q
                ->select(['id', 'league_id', 'mtgo_id'])
                ->where('state', '!=', MatchState::Started)
                ->whereNull('failed_at')])
            ->get()
            ->sortBy([
                fn (League $a, League $b) => ($b->event_id !== null) <=> ($a->event_id !== null),
                fn (League $a, League $b) => $b->started_at <=> $a->started_at,
            ])
            ->values();
    }
}
