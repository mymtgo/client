<?php

namespace App\Actions\Dashboard;

use App\Models\Account;
use App\Models\MtgoMatch;

class GetFormatChart
{
    /**
     * Build the 6-month format winrate chart data for the dashboard.
     */
    public static function run(): array
    {
        $accountId = Account::active()->value('id');

        $rows = MtgoMatch::complete()
            ->when($accountId, fn ($q, $id) => $q
                ->whereHas('deckVersion', fn ($q2) => $q2->whereHas('deck', fn ($q3) => $q3->where('account_id', $id)))
            )
            ->selectRaw("strftime('%Y-%m', started_at) as month, format, SUM(CASE WHEN games_won > games_lost THEN 1 ELSE 0 END) as wins, COUNT(*) as total")
            ->where('started_at', '>=', now()->subMonths(6)->startOfMonth())
            ->groupBy('month', 'format')
            ->get()
            ->map(fn ($r) => [
                'month' => $r->month,
                'format' => MtgoMatch::displayFormat($r->format),
                'winrate' => round($r->wins / $r->total * 100),
            ]);

        $monthDates = collect(range(5, 0))->map(fn ($i) => now()->subMonths($i)->startOfMonth());
        $months = $monthDates->map(fn ($m) => $m->format('M'))->values()->toArray();
        $monthKeys = $monthDates->map(fn ($m) => $m->format('Y-m'))->values()->toArray();
        $formats = $rows->pluck('format')->unique()->values()->toArray();

        $data = collect($monthKeys)->map(function ($monthKey, $x) use ($rows, $formats) {
            $point = ['x' => $x];
            foreach ($formats as $format) {
                $row = $rows->first(fn ($r) => $r['month'] === $monthKey && $r['format'] === $format);
                $point[$format] = $row ? $row['winrate'] : null;
            }

            return $point;
        })->values()->toArray();

        return compact('months', 'formats', 'data');
    }
}
