<?php

namespace App\Data\Front;

use Spatie\LaravelData\Data;

/** @typescript */
class DraftPickData extends Data
{
    /**
     * @param  array<int, int>  $available
     * @param  array<int, array{catalogId: int, atSeconds: int|null}>  $reservations
     * @param  array<int, int>  $wheeledIds  ids from this pack that came back at the wheel
     * @param  array<int, int>  $takenIds  ids from this pack gone by the wheel
     */
    public function __construct(
        public int $ordinal,
        public int $packNumber,
        public int $pickNumber,
        public string $label,
        public ?int $packId,
        public ?int $direction,
        public array $available,
        public ?int $pickedCatalogId,
        public array $reservations,
        public ?int $elapsedSeconds,
        public ?int $marginSeconds,
        public bool $indecisive,
        public ?string $shownAt,
        public ?string $deadlineAt,
        public ?string $pickedAt,
        public ?string $note,
        public ?string $noteSavedAt,
        public ?int $wheelReturnOrdinal,
        public array $wheeledIds,
        public array $takenIds,
    ) {}
}
