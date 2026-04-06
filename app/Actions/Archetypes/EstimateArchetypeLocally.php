<?php

namespace App\Actions\Archetypes;

use App\Models\Archetype;
use App\Models\Card;
use Illuminate\Support\Collection;

class EstimateArchetypeLocally
{
    private const QUANTITY_WEIGHT = 1.0;

    private const DISTINCT_WEIGHT = 0.35;

    private const COVERAGE_WEIGHT = 0.10;

    private const AMBIGUITY_THRESHOLD = 0.03;

    private const AMBIGUITY_PENALTY = 0.7;

    private const FORMAT_MAP = [
        'cmodern' => 'modern',
        'cpauper' => 'pauper',
        'clegacy' => 'legacy',
        'cvintage' => 'vintage',
        'cpremodern' => 'premodern',
    ];

    /**
     * Attempt to match a deck against locally-downloaded archetype decklists.
     *
     * Comparison uses oracle_id (printing-agnostic) so that different MTGO
     * printings of the same card are treated as equal.
     *
     * @param  Collection<int, array{mtgo_id: int, quantity: int}>  $cards
     * @return array{archetype_id: int, confidence: float}|null
     */
    public static function run(Collection $cards, string $format): ?array
    {
        // Capture full input size BEFORE filtering — unresolved cards still
        // dilute confidence so partial card lists don't produce false matches.
        $allInput = $cards->groupBy('mtgo_id')->map(fn ($group) => [
            'mtgo_id' => $group->first()['mtgo_id'],
            'quantity' => $group->sum(fn ($c) => $c['quantity']),
        ]);

        $inputDistinct = $allInput->count();
        $inputTotalQty = $allInput->sum('quantity');

        if ($inputDistinct === 0) {
            return null;
        }

        // Resolve mtgo_ids → oracle_ids for printing-agnostic matching
        $mtgoIds = $allInput->pluck('mtgo_id')->values()->toArray();
        $oracleMap = Card::whereIn('mtgo_id', $mtgoIds)
            ->whereNotNull('oracle_id')
            ->pluck('oracle_id', 'mtgo_id');

        $inputCards = $allInput->map(fn ($card) => [
            'oracle_id' => $oracleMap->get($card['mtgo_id']),
            'quantity' => $card['quantity'],
        ])->filter(fn ($card) => $card['oracle_id'] !== null)
            ->groupBy('oracle_id')
            ->map(fn ($group) => [
                'oracle_id' => $group->first()['oracle_id'],
                'quantity' => $group->sum('quantity'),
            ])->keyBy('oracle_id');

        if ($inputCards->isEmpty()) {
            return null;
        }

        $normalizedFormat = self::normalizeFormat($format);

        $candidates = Archetype::query()
            ->where('format', $normalizedFormat)
            ->whereNotNull('decklist_downloaded_at')
            ->with(['cards' => fn ($q) => $q->select('cards.id', 'cards.mtgo_id', 'cards.oracle_id')])
            ->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        $scores = [];

        foreach ($candidates as $archetype) {
            $deckCards = $archetype->cards
                ->filter(fn ($c) => $c->oracle_id !== null)
                ->keyBy('oracle_id');
            $deckDistinct = $deckCards->count();

            if ($deckDistinct === 0) {
                continue;
            }

            $matchedQty = 0;
            $matchedDistinct = 0;

            foreach ($inputCards as $oracleId => $inputCard) {
                $deckCard = $deckCards->get($oracleId);

                if (! $deckCard) {
                    continue;
                }

                $matchedDistinct++;
                $matchedQty += min($inputCard['quantity'], $deckCard->pivot->quantity);
            }

            if ($matchedDistinct === 0) {
                continue;
            }

            $quantityOverlap = $inputTotalQty > 0 ? $matchedQty / $inputTotalQty : 0;
            $distinctOverlap = $inputDistinct > 0 ? $matchedDistinct / $inputDistinct : 0;
            $deckCoverage = $deckDistinct > 0 ? $matchedDistinct / $deckDistinct : 0;

            $score = ($quantityOverlap * self::QUANTITY_WEIGHT)
                + ($distinctOverlap * self::DISTINCT_WEIGHT)
                + ($deckCoverage * self::COVERAGE_WEIGHT);

            $scores[] = [
                'archetype_id' => $archetype->id,
                'score' => $score,
            ];
        }

        if (empty($scores)) {
            return null;
        }

        usort($scores, fn ($a, $b) => $b['score'] <=> $a['score']);

        $best = $scores[0];
        $confidence = $best['score'];

        if (count($scores) > 1) {
            $second = $scores[1];

            if ($second['archetype_id'] !== $best['archetype_id']
                && ($best['score'] - $second['score']) < self::AMBIGUITY_THRESHOLD) {
                $confidence *= self::AMBIGUITY_PENALTY;
            }
        }

        return [
            'archetype_id' => $best['archetype_id'],
            'confidence' => round($confidence, 4),
        ];
    }

    private static function normalizeFormat(string $format): string
    {
        $lower = strtolower($format);

        return self::FORMAT_MAP[$lower] ?? $lower;
    }
}
