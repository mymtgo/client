<?php

namespace App\Actions\Reports;

use App\Enums\MatchOutcome;
use App\Enums\MatchState;
use App\Models\Archetype;
use App\Models\DeckVersion;
use App\Models\MtgoMatch;
use Carbon\Carbon;

class GetReportArchetypeStats
{
    /**
     * Aggregate KPIs for the Reports header.
     *
     * @param  array<int, int>  $deckVersionIds
     * @return array{
     *     deckCount: int,
     *     matchWins: int,
     *     matchLosses: int,
     *     matchDraws: int,
     *     matchWinrate: int,
     *     formatLabel: string,
     *     archetypeName: string,
     *     colorIdentity: string|null,
     * }|null
     */
    public static function run(?int $archetypeId, ?string $format, array $deckVersionIds, ?Carbon $from, ?Carbon $to): ?array
    {
        if ($archetypeId === null || $format === null || empty($deckVersionIds)) {
            return null;
        }

        $archetype = Archetype::find($archetypeId);
        if (! $archetype) {
            return null;
        }

        $versionTable = (new DeckVersion)->getTable();
        $deckCount = DeckVersion::query()
            ->whereIn($versionTable.'.id', $deckVersionIds)
            ->distinct()
            ->count('deck_id');

        $matchQuery = MtgoMatch::query()
            ->whereIn('deck_version_id', $deckVersionIds)
            ->where('state', MatchState::Complete)
            ->where('format', $format)
            ->when($from && $to, fn ($q) => $q->whereBetween('started_at', [$from, $to]));

        $wins = (clone $matchQuery)->where('outcome', MatchOutcome::Win)->count();
        $losses = (clone $matchQuery)->where('outcome', MatchOutcome::Loss)->count();
        $draws = (clone $matchQuery)->where('outcome', MatchOutcome::Draw)->count();

        $decisive = $wins + $losses;
        $winrate = $decisive > 0 ? (int) round(($wins / $decisive) * 100) : 0;

        return [
            'deckCount' => $deckCount,
            'matchWins' => $wins,
            'matchLosses' => $losses,
            'matchDraws' => $draws,
            'matchWinrate' => $winrate,
            'formatLabel' => MtgoMatch::displayFormat($format),
            'archetypeName' => $archetype->name,
            'colorIdentity' => $archetype->color_identity,
        ];
    }
}
