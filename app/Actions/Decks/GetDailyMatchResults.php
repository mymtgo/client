<?php

namespace App\Actions\Decks;

use App\Enums\MatchOutcome;
use App\Facades\AppSettings;
use App\Models\MtgoMatch;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class GetDailyMatchResults
{
    /**
     * Aggregates complete matches for the given deck versions into per-day
     * win/total counts, keyed by the calendar day in the user's system timezone.
     *
     * Matches are stored in UTC, and SQLite has no timezone-aware date
     * functions, so bucketing happens in PHP after converting each start time
     * to local wall-clock. Keys are sorted ascending.
     *
     * @param  Collection<int, int>  $versionIds
     * @return Collection<string, array{wins: int, total: int}>
     */
    public static function run(Collection $versionIds, Carbon $from, Carbon $to): Collection
    {
        $timezone = AppSettings::systemTimezone();

        return MtgoMatch::complete()
            ->select(['started_at', 'outcome'])
            ->whereIn('deck_version_id', $versionIds)
            ->whereBetween('started_at', [$from->copy()->utc(), $to->copy()->utc()])
            ->get()
            ->groupBy(fn (MtgoMatch $match) => $match->started_at->copy()->setTimezone($timezone)->format('Y-m-d'))
            ->map(fn (Collection $matches) => [
                'wins' => $matches->where('outcome', MatchOutcome::Win)->count(),
                'total' => $matches->count(),
            ])
            ->sortKeys();
    }
}
