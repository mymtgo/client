<?php

namespace App\Actions\Archetypes;

use App\Models\ArchetypeDeck;
use App\Models\Card;
use Illuminate\Support\Collection;

class EstimateArchetypeLocally
{
    private const QUANTITY_WEIGHT = 1.0;

    private const DISTINCT_WEIGHT = 0.35;

    private const COVERAGE_WEIGHT = 0.10;

    /**
     * Scores are normalised by the weight sum so confidence lives on a true
     * 0–1 scale, comparable to the API's confidence contract and to the
     * caller's local short-circuit threshold.
     */
    private const WEIGHT_SUM = self::QUANTITY_WEIGHT + self::DISTINCT_WEIGHT + self::COVERAGE_WEIGHT;

    private const AMBIGUITY_THRESHOLD = 0.03;

    private const AMBIGUITY_PENALTY = 0.7;

    /**
     * Matched distinct non-land cards needed for full local confidence. Below
     * this, confidence is scaled down so thin observations (e.g. an opponent
     * who only revealed a few cards) defer to the API instead of locking in a
     * match on weak evidence. Full coverage of a small deck bypasses this.
     */
    private const MIN_CONFIDENT_MATCHES = 8;

    private const FORMAT_MAP = [
        'cmodern' => 'modern',
        'cpauper' => 'pauper',
        'clegacy' => 'legacy',
        'cvintage' => 'vintage',
        'cpremodern' => 'premodern',
        'cpioneer' => 'pioneer',
        'cstandard' => 'standard',
    ];

    /**
     * Attempt to match a deck against locally-downloaded archetype decklists.
     *
     * Each ArchetypeDeck variant is scored individually. The archetype_id is
     * derived from the best-matching deck. Ambiguity is checked at the parent
     * archetype level — two variants of the same archetype tying does not
     * trigger the penalty.
     *
     * @param  Collection<int, array{mtgo_id: int|string, quantity: int}>  $cards
     * @return array{archetype_id: int, archetype_deck_id: int, confidence: float}|null
     */
    public static function run(Collection $cards, string $format): ?array
    {
        // Collapse duplicate mtgo_ids, summing quantities.
        $allInput = $cards->groupBy('mtgo_id')->map(fn ($group) => [
            'mtgo_id' => $group->first()['mtgo_id'],
            'quantity' => $group->sum(fn ($c) => $c['quantity']),
        ]);

        if ($allInput->isEmpty()) {
            return null;
        }

        // Resolve mtgo_ids → oracle_ids + type for printing-agnostic matching.
        $mtgoIds = $allInput->pluck('mtgo_id')->values()->toArray();
        $cardMeta = Card::whereIn('mtgo_id', $mtgoIds)
            ->whereNotNull('oracle_id')
            ->get(['mtgo_id', 'oracle_id', 'type'])
            ->keyBy('mtgo_id');

        // Basic lands appear across nearly every deck, so they cannot
        // discriminate archetypes — drop them entirely; they neither match nor
        // count toward the denominator. Nonbasic lands stay: for land-defined
        // decks (Tron, Amulet, Affinity's artifact lands) they are the
        // strongest identity signal the opponent reveals. Unresolved cards
        // remain so partial card lists still dilute confidence.
        $nonLandInput = $allInput->reject(function ($card) use ($cardMeta) {
            $meta = $cardMeta->get($card['mtgo_id']);

            return $meta && self::isBasicLand((string) $meta->type);
        });

        $inputDistinct = $nonLandInput->count();
        $inputTotalQty = $nonLandInput->sum('quantity');

        if ($inputDistinct === 0) {
            return null;
        }

        $inputCards = $nonLandInput->map(fn ($card) => [
            'oracle_id' => $cardMeta->get($card['mtgo_id'])?->oracle_id,
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

        $candidateDecks = ArchetypeDeck::query()
            ->whereHas('archetype', fn ($q) => $q->where('format', $normalizedFormat))
            ->with([
                'archetype:id',
                'cards' => fn ($q) => $q->select('cards.id', 'cards.mtgo_id', 'cards.oracle_id', 'cards.type'),
            ])
            ->get();

        if ($candidateDecks->isEmpty()) {
            return null;
        }

        $scores = [];

        foreach ($candidateDecks as $deck) {
            $deckCards = $deck->cards
                ->filter(fn ($c) => $c->oracle_id !== null && ! self::isBasicLand((string) $c->type))
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

            $score = (($quantityOverlap * self::QUANTITY_WEIGHT)
                + ($distinctOverlap * self::DISTINCT_WEIGHT)
                + ($deckCoverage * self::COVERAGE_WEIGHT)) / self::WEIGHT_SUM;

            $scores[] = [
                'archetype_id' => $deck->archetype_id,
                'archetype_deck_id' => $deck->id,
                'score' => $score,
                'matched_distinct' => $matchedDistinct,
                'deck_coverage' => $deckCoverage,
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

        // Scale confidence by how much evidence backs the match. Few matched
        // cards relative to MIN_CONFIDENT_MATCHES drags confidence down, unless
        // we have covered most of a genuinely small deck.
        $evidenceFactor = min(1.0, max(
            $best['matched_distinct'] / self::MIN_CONFIDENT_MATCHES,
            $best['deck_coverage'],
        ));

        $confidence *= $evidenceFactor;

        return [
            'archetype_id' => $best['archetype_id'],
            'archetype_deck_id' => $best['archetype_deck_id'],
            'confidence' => round($confidence, 4),
        ];
    }

    private static function isBasicLand(string $type): bool
    {
        return str_contains($type, 'Basic') && str_contains($type, 'Land');
    }

    private static function normalizeFormat(string $format): string
    {
        $lower = strtolower($format);

        return self::FORMAT_MAP[$lower] ?? $lower;
    }
}
