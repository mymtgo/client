<?php

namespace App\Actions\Reports;

use App\Data\Front\MatchRecordData;
use App\Enums\MatchState;
use App\Models\Archetype;
use App\Models\DeckVersion;
use App\Models\MtgoMatch;
use App\Support\MatchRecord;
use Carbon\Carbon;

class GetReportArchetypeStats
{
    /**
     * Aggregate KPIs for the Reports header.
     *
     * @param  array<int, int>  $deckVersionIds
     * @return array{
     *     deckCount: int,
     *     matchRecord: MatchRecordData,
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

        $matchRecord = MatchRecord::fromQuery($matchQuery);

        return [
            'deckCount' => $deckCount,
            'matchRecord' => $matchRecord->toData(),
            'formatLabel' => MtgoMatch::displayFormat($format),
            'archetypeName' => $archetype->name,
            'colorIdentity' => $archetype->color_identity,
        ];
    }
}
