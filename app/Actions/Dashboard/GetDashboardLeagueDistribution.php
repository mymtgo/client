<?php

namespace App\Actions\Dashboard;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class GetDashboardLeagueDistribution
{
    /**
     * League records for the selected window, bucketed 5-0 down to 0-5.
     *
     * A league is placed by its last match, not by each match's own date. The
     * matches themselves are never filtered: counting only the ones inside the
     * window would report a league played across the edge as 3-0, which is not
     * a league result at all, and it would silently vanish from the buckets.
     *
     * The league's own `completed_at` is not the anchor because it is not
     * always populated, while its matches always have dates.
     *
     * @return array{buckets: array<string, int>, trophies: int, total: int}
     */
    public static function run(?int $accountId, Carbon $from, Carbon $to, ?string $format = null): array
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
            ->where('l.state', 'complete')
            ->where('m.state', 'complete')
            ->when($format, fn ($q, $f) => $q->where('m.format', $f))
            ->groupBy('l.id')
            ->havingRaw('MAX(m.started_at) BETWEEN ? AND ?', [$from, $to])
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
