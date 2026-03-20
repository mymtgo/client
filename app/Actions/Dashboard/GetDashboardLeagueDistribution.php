<?php

namespace App\Actions\Dashboard;

use Illuminate\Support\Facades\DB;

class GetDashboardLeagueDistribution
{
    /**
     * @return array{buckets: array<string, int>, trophies: int, total: int}
     */
    public static function run(?int $accountId): array
    {
        $buckets = collect(['5-0' => 0, '4-1' => 0, '3-2' => 0, '2-3' => 0, '1-4' => 0, '0-5' => 0]);

        if (! $accountId) {
            return ['buckets' => $buckets->all(), 'trophies' => 0, 'total' => 0];
        }

        // Scope through match -> deck_version -> deck -> account
        $leagueRecords = DB::table('leagues as l')
            ->join('matches as m', 'm.league_id', '=', 'l.id')
            ->join('deck_versions as dv', 'dv.id', '=', 'm.deck_version_id')
            ->join('decks as d', 'd.id', '=', 'dv.deck_id')
            ->where('d.account_id', $accountId)
            ->where('l.phantom', false)
            ->where('l.state', 'complete')
            ->where('m.state', 'complete')
            ->groupBy('l.id')
            ->selectRaw("
                l.id,
                SUM(CASE WHEN m.outcome = 'win' THEN 1 ELSE 0 END) as wins,
                SUM(CASE WHEN m.outcome = 'loss' THEN 1 ELSE 0 END) as losses
            ")
            ->get();

        foreach ($leagueRecords as $record) {
            $key = "{$record->wins}-{$record->losses}";
            if ($buckets->has($key)) {
                $buckets->put($key, $buckets->get($key) + 1);
            }
        }

        $trophies = $buckets->get('5-0', 0);
        $total = $buckets->sum();

        return [
            'buckets' => $buckets->all(),
            'trophies' => $trophies,
            'total' => $total,
        ];
    }
}
