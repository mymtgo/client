<?php

namespace App\Actions\Decks;

use App\Actions\Util\TimeframeRange;
use App\Models\Deck;
use App\Models\DeckVersion;
use App\Support\MatchRecord;
use Carbon\Carbon;

class GetDeckWinrateDelta
{
    /**
     * How this deck's match win rate moved against the preceding window.
     *
     * An all-time window has no window before it, so the comparison is
     * reported as absent rather than as a delta against an empty century.
     * A previous window with no matches is absent for the same reason: a
     * swing measured from nothing is noise dressed as a trend.
     *
     * @return array{previousRate: int|null, previousTotal: int, delta: int|null}
     */
    public static function run(Deck $deck, Carbon $currentStart, string $timeframe, ?DeckVersion $deckVersion = null): array
    {
        $absent = ['previousRate' => null, 'previousTotal' => 0, 'delta' => null];

        if ($timeframe === 'alltime') {
            return $absent;
        }

        [$previousStart, $previousEnd] = TimeframeRange::previous($timeframe, $currentStart);

        $previous = MatchRecord::fromQuery(
            $deck->matches()
                ->whereBetween('matches.started_at', [$previousStart, $previousEnd])
                ->when($deckVersion, fn ($q) => $q->where('matches.deck_version_id', $deckVersion->id))
                ->getQuery()
        );

        if ($previous->total() === 0) {
            return $absent;
        }

        [, $currentEnd] = TimeframeRange::run($timeframe);

        $current = MatchRecord::fromQuery(
            $deck->matches()
                ->whereBetween('matches.started_at', [$currentStart, $currentEnd])
                ->when($deckVersion, fn ($q) => $q->where('matches.deck_version_id', $deckVersion->id))
                ->getQuery()
        );

        return [
            'previousRate' => $previous->winrate(),
            'previousTotal' => $previous->total(),
            'delta' => $current->winrate() - $previous->winrate(),
        ];
    }
}
