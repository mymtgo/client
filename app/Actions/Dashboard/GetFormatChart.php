<?php

namespace App\Actions\Dashboard;

use App\Models\Account;
use App\Models\MtgoMatch;

class GetFormatChart
{
    /**
     * Build a daily format winrate chart for the dashboard (last 30 days).
     */
    public static function run(): array
    {
        $accountId = Account::active()->value('id');
        $startDate = now()->subDays(29)->startOfDay();

        $rows = MtgoMatch::complete()
            ->when($accountId, fn ($q, $id) => $q
                ->whereHas('deckVersion', fn ($q2) => $q2->whereHas('deck', fn ($q3) => $q3->where('account_id', $id)))
            )
            ->selectRaw("strftime('%Y-%m-%d', started_at) as day, format, SUM(CASE WHEN outcome = 'win' THEN 1 ELSE 0 END) as wins, COUNT(*) as total")
            ->where('started_at', '>=', $startDate)
            ->groupBy('day', 'format')
            ->get()
            ->map(fn ($r) => [
                'day' => $r->day,
                'format' => MtgoMatch::displayFormat($r->format),
                'winrate' => round($r->wins / $r->total * 100),
            ]);

        $dayDates = collect(range(29, 0))->map(fn ($i) => now()->subDays($i)->startOfDay());
        $labels = $dayDates->map(fn ($d) => $d->format('M j'))->values()->toArray();
        $dayKeys = $dayDates->map(fn ($d) => $d->format('Y-m-d'))->values()->toArray();
        $formats = $rows->pluck('format')->unique()->values()->toArray();

        $data = collect($dayKeys)->map(function ($dayKey, $x) use ($rows, $formats) {
            $point = ['x' => $x];
            foreach ($formats as $format) {
                $row = $rows->first(fn ($r) => $r['day'] === $dayKey && $r['format'] === $format);
                $point[$format] = $row ? $row['winrate'] : null;
            }

            return $point;
        })->values()->toArray();

        return ['months' => $labels, 'formats' => $formats, 'data' => $data];
    }
}
