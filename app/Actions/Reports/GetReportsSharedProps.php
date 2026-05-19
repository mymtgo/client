<?php

namespace App\Actions\Reports;

use Carbon\Carbon;
use Illuminate\Support\Collection;

class GetReportsSharedProps
{
    /**
     * Compose the shared props bundle for Reports pages.
     *
     * `deckVersionIds` is included so controllers can feed it into their
     * page-specific report query; it should be unset before passing the
     * remaining props to Inertia::render.
     *
     * @return array{
     *     archetypeOptions: Collection,
     *     formatOptions: Collection,
     *     selectedArchetype: int|null,
     *     selectedFormat: string|null,
     *     timeframe: string,
     *     currentPage: string,
     *     matchCount: int,
     *     deckVersionIds: array<int, int>,
     *     archetypeStats: array{
     *         deckCount: int,
     *         matchWins: int,
     *         matchLosses: int,
     *         matchDraws: int,
     *         matchWinrate: int,
     *         formatLabel: string,
     *         archetypeName: string,
     *         colorIdentity: string|null,
     *     }|null,
     * }
     */
    public static function run(
        ?int $archetypeId,
        ?string $format,
        string $timeframe,
        ?Carbon $from,
        ?Carbon $to,
        string $currentPage,
    ): array {
        $deckVersionIds = ($archetypeId !== null && $format !== null)
            ? GetReportDeckVersionIds::run($archetypeId, $format, $from, $to)
            : [];

        return [
            'archetypeOptions' => GetReportArchetypeOptions::run(),
            'formatOptions' => $archetypeId !== null ? GetReportFormatOptions::run($archetypeId) : collect(),
            'selectedArchetype' => $archetypeId,
            'selectedFormat' => $format,
            'timeframe' => $timeframe,
            'currentPage' => $currentPage,
            'matchCount' => ! empty($deckVersionIds) && $format !== null
                ? GetReportMatchCount::run($deckVersionIds, $format, $from, $to)
                : 0,
            'deckVersionIds' => $deckVersionIds,
            'archetypeStats' => GetReportArchetypeStats::run($archetypeId, $format, $deckVersionIds, $from, $to),
        ];
    }
}
