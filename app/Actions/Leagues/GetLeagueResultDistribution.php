<?php

namespace App\Actions\Leagues;

use App\Models\Deck;
use App\Models\League;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class GetLeagueResultDistribution
{
    /**
     * Get the W-L distribution of completed real leagues for a deck.
     *
     * @param  Collection  $matchIds  Match IDs belonging to this deck
     * @return array<string, int> e.g. ['5-0' => 2, '4-1' => 3, ...]
     */
    public static function run(Deck $deck, Collection $matchIds): array
    {
        $buckets = collect(['5-0' => 0, '4-1' => 0, '3-2' => 0, '2-3' => 0, '1-4' => 0, '0-5' => 0]);

        $leagues = League::whereHas('matches', fn ($q) => $q->whereIn('matches.id', $matchIds))
            ->where('phantom', false)
            ->where('state', 'complete')
            ->pluck('id');

        if ($leagues->isEmpty()) {
            return $buckets->all();
        }

        $leagueRecords = DB::table('matches as m')
            ->join('deck_versions as dv', 'dv.id', '=', 'm.deck_version_id')
            ->whereIn('m.league_id', $leagues)
            ->where('dv.deck_id', $deck->id)
            ->where('m.state', 'complete')
            ->selectRaw('m.league_id, SUM(CASE WHEN m.games_won > m.games_lost THEN 1 ELSE 0 END) as wins, SUM(CASE WHEN m.games_won < m.games_lost THEN 1 ELSE 0 END) as losses')
            ->groupBy('m.league_id')
            ->get();

        foreach ($leagueRecords as $record) {
            $key = "{$record->wins}-{$record->losses}";
            if ($buckets->has($key)) {
                $buckets->put($key, $buckets->get($key) + 1);
            }
        }

        return $buckets->all();
    }
}
