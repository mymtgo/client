<?php

namespace App\Data\Front;

use Spatie\LaravelData\Data;

/** @typescript */
class LimitedIndexRowData extends Data
{
    /**
     * @param  array<int, 'W'|'L'|null>  $results
     * @param  array<int, string>  $opponents
     * @param  array<int, array<string, mixed>>  $matches  Run match rows, shaped by FormatLeagueRuns
     * @param  array{wins: int, losses: int}  $onPlayRecord
     * @param  array{wins: int, losses: int}  $onDrawRecord
     */
    public function __construct(
        public ?int $leagueId,
        public ?int $draftId,
        public string $title,
        public ?string $setCode,
        public string $kind,
        public string $state,
        public string $stateVariant,
        public ?string $startedAt,
        public ?string $startedAtHuman,
        public int $wins,
        public int $losses,
        public array $results,
        public int $picksMade,
        public int $picksExpected,
        public bool $deckRegistered,
        public int $versionCount,
        public ?int $avgPickSeconds,
        public array $opponents,
        public array $matches,
        public int $gameWins,
        public int $gameLosses,
        public array $onPlayRecord,
        public array $onDrawRecord,
        public ?string $note,
        public bool $linked,
    ) {}
}
