<?php

namespace App\Actions\Dashboard;

use App\Models\Account;
use App\Models\MtgoMatch;
use Carbon\Carbon;

class GetFormatChart
{
    /**
     * Build the daily format winrate chart data for the dashboard.
     */
    public static function run(Carbon $start, Carbon $end): array
    {
        $accountId = Account::active()->value('id');

        $rows = MtgoMatch::complete()
            ->when($accountId, fn ($q, $id) => $q
                ->whereHas('deckVersion', fn ($q2) => $q2->whereHas('deck', fn ($q3) => $q3->where('account_id', $id)))
            )
            ->selectRaw("strftime('%Y-%m-%d', started_at) as day, format, SUM(CASE WHEN outcome = 'win' THEN 1 ELSE 0 END) as wins, COUNT(*) as total")
            ->whereBetween('started_at', [$start, $end])
            ->groupBy('day', 'format')
            ->get()
            ->map(fn ($r) => [
                'day' => $r->day,
                'format' => MtgoMatch::displayFormat($r->format),
                'winrate' => round($r->wins / $r->total * 100),
            ]);

        // Build day labels for the full range
        $dayDates = collect();
        $current = $start->copy()->startOfDay();
        $endDay = $end->copy()->startOfDay();

        while ($current->lte($endDay)) {
            $dayDates->push($current->copy());
            $current->addDay();
        }

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

        return ['labels' => $labels, 'formats' => $formats, 'data' => $data];
    }
}
