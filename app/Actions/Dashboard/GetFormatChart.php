<?php

namespace App\Actions\Dashboard;

use App\Models\Account;
use App\Models\MtgoMatch;
use Carbon\Carbon;

class GetFormatChart
{
    /**
     * Build winrate-by-format breakdown for the dashboard.
     *
     * @return array<int, array{format: string, wins: int, losses: int, total: int, winrate: int}>
     */
    public static function run(Carbon $start, Carbon $end): array
    {
        $accountId = Account::active()->value('id');

        return MtgoMatch::complete()
            ->when($accountId, fn ($q, $id) => $q
                ->whereHas('deckVersion', fn ($q2) => $q2->whereHas('deck', fn ($q3) => $q3->where('account_id', $id)))
            )
            ->whereBetween('started_at', [$start, $end])
            ->selectRaw("
                format,
                SUM(CASE WHEN outcome = 'win' THEN 1 ELSE 0 END) as wins,
                SUM(CASE WHEN outcome = 'loss' THEN 1 ELSE 0 END) as losses,
                COUNT(*) as total
            ")
            ->groupBy('format')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r) => [
                'format' => MtgoMatch::displayFormat($r->format),
                'wins' => (int) $r->wins,
                'losses' => (int) $r->losses,
                'total' => (int) $r->total,
                'winrate' => $r->total > 0 ? (int) round($r->wins / $r->total * 100) : 0,
            ])
            ->values()
            ->all();
    }
}
