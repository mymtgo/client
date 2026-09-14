<?php

namespace App\Actions\Dashboard;

use App\Data\Front\MatchRecordData;
use App\Support\MatchRecord;
use App\Support\OpponentMatchPairs;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class GetDashboardMatchupSpread
{
    /**
     * Account-wide matchup spread — top 5 opponent archetypes by match count.
     *
     * @return array<int, array{name: string, record: MatchRecordData}>
     */
    public static function run(?int $accountId, Carbon $from, Carbon $to, ?string $format = null): array
    {
        if (! $accountId) {
            return [];
        }

        return DB::table('matches as m')
            ->join('deck_versions as dv', 'dv.id', '=', 'm.deck_version_id')
            ->join('decks as d', 'd.id', '=', 'dv.deck_id')
            ->join('match_archetypes as ma', 'ma.mtgo_match_id', '=', 'm.id')
            ->join('archetypes as a', 'a.id', '=', 'ma.archetype_id')
            ->joinSub(OpponentMatchPairs::query(), 'opp', OpponentMatchPairs::on())
            ->where('d.account_id', $accountId)
            ->where('m.state', 'complete')
            ->when($format, fn ($q, $f) => $q->where('m.format', $f))
            ->whereBetween('m.started_at', [$from, $to])
            ->groupBy('a.id', 'a.name')
            ->selectRaw("
                a.name as name,
                COUNT(DISTINCT CASE WHEN m.outcome = 'win' THEN m.id END) as wins,
                COUNT(DISTINCT CASE WHEN m.outcome = 'loss' THEN m.id END) as losses,
                COUNT(DISTINCT m.id) as match_count
            ")
            ->orderByDesc('match_count')
            ->limit(5)
            ->get()
            ->map(fn ($r) => [
                'name' => $r->name,
                'record' => MatchRecord::fromTotal((int) $r->wins, (int) $r->losses, (int) $r->match_count)->toData(),
            ])
            ->all();
    }
}
