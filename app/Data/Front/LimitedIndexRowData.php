<?php

namespace App\Data\Front;

use Spatie\LaravelData\Data;

/** @typescript */
class LimitedIndexRowData extends Data
{
    /**
     * @param  array<int, 'W'|'L'|null>  $results
     * @param  array<int, string>  $opponents
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
        public ?string $note,
        public bool $linked,
    ) {}
}
