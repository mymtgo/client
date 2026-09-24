<?php

namespace App\Actions\Leagues;

use App\Enums\LeagueState;
use App\Models\Deck;
use App\Models\League;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class GetLeagueResultDistribution
{
    /**
     * Get the W-L distribution of completed leagues for a deck, plus how many
     * five-round runs it was dropped from.
     *
     * A dropped run never lands in a bucket: its record is whatever it had when
     * the player left. Partial leagues are not drops, since the app did not see
     * how they ended, and draft leagues are left out because the buckets only
     * cover five-round runs.
     *
     * @param  Collection  $matchIds  Match IDs belonging to this deck
     * @return array<string, int> e.g. ['5-0' => 2, '4-1' => 3, ..., 'dropped' => 1]
     */
    public static function run(Deck $deck, Collection $matchIds): array
    {
        $buckets = collect(['5-0' => 0, '4-1' => 0, '3-2' => 0, '2-3' => 0, '1-4' => 0, '0-5' => 0]);

        $dropped = 0;

        $leagues = League::whereHas('matches', fn ($q) => $q->whereIn('matches.id', $matchIds))
            ->whereIn('state', [LeagueState::Complete, LeagueState::Dropped])
            ->get(['id', 'state', 'kind']);

        if ($leagues->isEmpty()) {
            return $buckets->put('dropped', $dropped)->all();
        }

        $droppedIds = $leagues->where('state', LeagueState::Dropped)->pluck('id');
        $dropped = $leagues
            ->where('state', LeagueState::Dropped)
            ->filter(fn (League $league) => $league->kind->roundCount() === 5)
            ->count();

        $leagueRecords = DB::table('matches as m')
            ->join('deck_versions as dv', 'dv.id', '=', 'm.deck_version_id')
            ->whereIn('m.league_id', $leagues->pluck('id')->diff($droppedIds))
            ->where('dv.deck_id', $deck->id)
            ->where('m.state', 'complete')
            ->selectRaw("m.league_id, SUM(CASE WHEN m.outcome = 'win' THEN 1 ELSE 0 END) as wins, SUM(CASE WHEN m.outcome = 'loss' THEN 1 ELSE 0 END) as losses")
            ->groupBy('m.league_id')
            ->get();

        foreach ($leagueRecords as $record) {
            $key = "{$record->wins}-{$record->losses}";
            if ($buckets->has($key)) {
                $buckets->put($key, $buckets->get($key) + 1);
            }
        }

        return $buckets->put('dropped', $dropped)->all();
    }
}
