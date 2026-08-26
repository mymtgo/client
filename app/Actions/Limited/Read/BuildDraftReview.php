<?php

namespace App\Actions\Limited\Read;

use App\Actions\Limited\Analytics\ComputeDraftSignals;
use App\Actions\Limited\Analytics\ComputePickTimings;
use App\Actions\Limited\Analytics\ComputeSeenWheel;
use App\Data\Front\DraftPickData;
use App\Data\Front\LimitedCardData;
use App\Models\Card;
use App\Models\Draft;
use App\Models\DraftPick;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class BuildDraftReview
{
    /**
     * Everything the Draft page needs for all picks at once.
     *
     * @return array{header: array<string, mixed>, picks: array<int, DraftPickData>, signals: array<int, array<string, mixed>>, seenWheel: array<int, array<string, mixed>>, cards: array<string, LimitedCardData>}
     */
    public static function run(Draft $draft): array
    {
        $picks = $draft->picks()->get();
        $seatCount = (int) ($draft->seat_count ?: 8);

        $seenWheel = ComputeSeenWheel::run($picks, $seatCount);
        $timings = ComputePickTimings::summary($picks);

        $ids = $picks->flatMap(fn (DraftPick $p) => [
            ...array_map('intval', $p->cards_available ?? []),
            ...self::reservationIds($p),
            ...($p->picked_catalog_id !== null ? [(int) $p->picked_catalog_id] : []),
        ])->unique()->values();

        $cards = ResolveCatalogCards::run($ids);
        $cardData = $ids->mapWithKeys(fn (int $id) => [(string) $id => LimitedCardData::fromCatalog($id, $cards->get((string) $id))])->all();

        $pickData = $picks->map(fn (DraftPick $pick) => self::pickData($pick, $picks, $seatCount))->values()->all();

        return [
            'header' => [
                'seatIndex' => $draft->seat_index,
                'seatCount' => $seatCount,
                'boosterCatalogId' => $draft->booster_catalog_id,
                'packSize' => (int) $draft->pack_size,
                'picksMade' => $picks->whereNotNull('picked_catalog_id')->count(),
                'picksExpected' => (int) $draft->picks_expected,
                'startedAt' => $draft->started_at?->toIso8601String(),
                'endedAt' => $draft->ended_at?->toIso8601String(),
                'durationMinutes' => $draft->started_at && $draft->ended_at ? (int) round($draft->started_at->diffInSeconds($draft->ended_at, true) / 60) : null,
                'avgPickSeconds' => $timings['avg_seconds'],
                'avgMarginSeconds' => $timings['avg_margin_seconds'],
                'indecisiveCount' => $timings['indecisive_count'],
                'fastestPack' => $timings['fastest_pack'],
                'slowestPack' => $timings['slowest_pack'],
                'colorsPicked' => self::colorsPicked($picks, $cards),
                'state' => $draft->state->value,
            ],
            'picks' => $pickData,
            'signals' => ComputeDraftSignals::run($seenWheel, $cards),
            'seenWheel' => $seenWheel,
            'cards' => $cardData,
        ];
    }

    /**
     * @param  Collection<int, DraftPick>  $picks
     */
    private static function pickData(DraftPick $pick, Collection $picks, int $seatCount): DraftPickData
    {
        $timing = ComputePickTimings::forPick($pick);
        $wheel = ComputeSeenWheel::wheelForPick($picks, $pick, $seatCount);

        $reservations = collect($pick->reservations ?? [])
            ->filter(fn ($reservation) => self::catalogId($reservation) !== null)
            ->map(function (array $reservation) use ($pick) {
                $at = self::reservedAt($reservation);

                return [
                    'catalogId' => self::catalogId($reservation),
                    'atSeconds' => $at && $pick->shown_at ? max(0, (int) $pick->shown_at->diffInSeconds($at, true)) : null,
                ];
            })->values()->all();

        return new DraftPickData(
            ordinal: (int) $pick->ordinal,
            packNumber: (int) $pick->pack_number,
            pickNumber: (int) $pick->pick_number,
            label: "P{$pick->pack_number}p{$pick->pick_number}",
            packId: $pick->pack_id !== null ? (int) $pick->pack_id : null,
            direction: $pick->direction !== null ? (int) $pick->direction : null,
            available: array_map('intval', $pick->cards_available ?? []),
            pickedCatalogId: $pick->picked_catalog_id !== null ? (int) $pick->picked_catalog_id : null,
            reservations: $reservations,
            elapsedSeconds: $timing['elapsed_seconds'],
            marginSeconds: $timing['margin_seconds'],
            indecisive: $timing['indecisive'],
            shownAt: $pick->shown_at?->toIso8601String(),
            deadlineAt: $pick->deadline_at?->toIso8601String(),
            pickedAt: $pick->picked_at?->toIso8601String(),
            note: $pick->note,
            noteSavedAt: $pick->note !== null ? $pick->updated_at?->toIso8601String() : null,
            wheelReturnOrdinal: $wheel['return_ordinal'] ?? null,
            wheeledIds: $wheel['survived'] ?? [],
            takenIds: $wheel['taken'] ?? [],
        );
    }

    /**
     * Catalog ids of every usable reservation on a pick. Reservations come
     * from the log and can be malformed, so entries without a numeric
     * catalog_id are dropped rather than resolved as id 0.
     *
     * @return array<int, int>
     */
    private static function reservationIds(DraftPick $pick): array
    {
        return collect($pick->reservations ?? [])
            ->map(fn ($reservation) => self::catalogId($reservation))
            ->filter(fn (?int $id) => $id !== null)
            ->values()
            ->all();
    }

    /**
     * The catalog id of one reservation entry, or null when it is unusable.
     */
    private static function catalogId(mixed $reservation): ?int
    {
        if (! is_array($reservation) || ! isset($reservation['catalog_id']) || ! is_numeric($reservation['catalog_id'])) {
            return null;
        }

        return (int) $reservation['catalog_id'];
    }

    /**
     * When a reservation was made. The `at` key is optional and can be an
     * empty string, which Carbon would otherwise parse as "now".
     *
     * @param  array<string, mixed>  $reservation
     */
    private static function reservedAt(array $reservation): ?Carbon
    {
        $at = $reservation['at'] ?? null;

        if (! is_string($at) || trim($at) === '') {
            return null;
        }

        return rescue(fn () => Carbon::parse($at), null, false);
    }

    /**
     * Count of picked cards per colour letter, multicolour counted once per colour, colourless as C.
     *
     * @param  Collection<int, DraftPick>  $picks
     * @param  Collection<string, Card>  $cards
     * @return array<string, int>
     */
    private static function colorsPicked(Collection $picks, Collection $cards): array
    {
        $tally = ['W' => 0, 'U' => 0, 'B' => 0, 'R' => 0, 'G' => 0, 'C' => 0];

        foreach ($picks as $pick) {
            if ($pick->picked_catalog_id === null) {
                continue;
            }
            $card = $cards->get((string) $pick->picked_catalog_id);
            $colors = $card ? array_intersect(str_split((string) $card->colors), ['W', 'U', 'B', 'R', 'G']) : [];
            if ($colors === []) {
                $tally['C']++;

                continue;
            }
            foreach ($colors as $c) {
                $tally[$c]++;
            }
        }

        return array_filter($tally);
    }
}
