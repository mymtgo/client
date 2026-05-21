<?php

namespace App\Actions\Matches;

use App\Enums\MatchState;
use App\Models\MtgoMatch;

class ReconcileStuckMatches
{
    public const STALE_AFTER_MINUTES = 10;

    public const PER_TICK_LIMIT = 50;

    public static function run(): void
    {
        MtgoMatch::query()
            ->whereIn('state', [
                MatchState::Started,
                MatchState::InProgress,
                MatchState::Ended,
            ])
            ->whereNull('failed_at')
            ->where('updated_at', '<', now()->subMinutes(self::STALE_AFTER_MINUTES))
            ->orderBy('updated_at')
            ->limit(self::PER_TICK_LIMIT)
            ->get()
            ->each(function (MtgoMatch $match): void {
                $result = ParseMatchHistory::findResult($match->mtgo_id);

                if ($result === null) {
                    return;
                }

                ResolveMatchFromHistory::run($match, $result);
            });
    }
}
