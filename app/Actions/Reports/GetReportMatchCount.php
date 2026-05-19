<?php

namespace App\Actions\Reports;

use App\Enums\MatchState;
use App\Models\MtgoMatch;
use Carbon\Carbon;

class GetReportMatchCount
{
    /**
     * @param  array<int, int>  $deckVersionIds
     */
    public static function run(array $deckVersionIds, string $format, ?Carbon $from, ?Carbon $to): int
    {
        if (empty($deckVersionIds)) {
            return 0;
        }

        return MtgoMatch::query()
            ->whereIn('deck_version_id', $deckVersionIds)
            ->where('state', MatchState::Complete)
            ->where('format', $format)
            ->when($from && $to, fn ($q) => $q->whereBetween('started_at', [$from, $to]))
            ->count();
    }
}
