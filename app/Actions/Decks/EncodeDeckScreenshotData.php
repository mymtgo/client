<?php

namespace App\Actions\Decks;

use App\Actions\Cards\GetCards;
use App\Models\Card;
use App\Models\DeckVersion;
use Illuminate\Support\Facades\Storage;

class EncodeDeckScreenshotData
{
    /** Type sort order: Creature → Planeswalker → Instant → Sorcery → Artifact → Enchantment */
    private const TYPE_ORDER = [
        'Creature' => 1,
        'Planeswalker' => 2,
        'Instant' => 3,
        'Sorcery' => 4,
        'Artifact' => 5,
        'Enchantment' => 6,
    ];

    private const CANONICAL_TYPES = ['Creature', 'Planeswalker', 'Battle', 'Instant', 'Sorcery', 'Enchantment', 'Artifact'];

    /**
     * @return array{nonLandCards: array, landCards: array, sideboardCards: array, cmcDistribution: array, typeDistribution: array}
     */
    public static function run(DeckVersion $deckVersion): array
    {
        $cardRefs = $deckVersion->cards;
        $cardModels = GetCards::run($cardRefs)->keyBy('oracle_id');

        // Build base64 image cache (unique cards only)
        $imageCache = [];
        foreach ($cardModels as $card) {
            $imageCache[$card->oracle_id] = self::toBase64($card);
        }

        $nonLandCards = [];
        $landCards = [];
        $sideboardCards = [];
        $cmcBuckets = [];
        $typeCounts = [];

        foreach ($cardRefs as $ref) {
            $card = $cardModels->get($ref['oracle_id']);
            if (! $card) {
                continue;
            }

            $normalizedType = self::normalizeType($card->type ?? '');
            $isSideboard = ($ref['sideboard'] ?? 'false') === 'true';
            $quantity = (int) ($ref['quantity'] ?? 1);

            $entry = [
                'name' => $card->name,
                'type' => $normalizedType,
                'quantity' => $quantity,
                'imageBase64' => $imageCache[$card->oracle_id] ?? null,
            ];

            if ($isSideboard) {
                $sideboardCards[] = $entry;
            } elseif ($normalizedType === 'Land') {
                $landCards[] = $entry;
                $typeCounts['Land'] = ($typeCounts['Land'] ?? 0) + $quantity;
            } else {
                $nonLandCards[] = $entry;
                $cmc = min((int) ($card->cmc ?? 0), 7);
                $cmcKey = $cmc >= 7 ? '7+' : (string) $cmc;
                $cmcBuckets[$cmcKey] = ($cmcBuckets[$cmcKey] ?? 0) + $quantity;
                $typeCounts[$normalizedType] = ($typeCounts[$normalizedType] ?? 0) + $quantity;
            }
        }

        usort($nonLandCards, function ($a, $b) {
            $typeA = self::TYPE_ORDER[$a['type']] ?? 99;
            $typeB = self::TYPE_ORDER[$b['type']] ?? 99;
            if ($typeA !== $typeB) {
                return $typeA - $typeB;
            }

            return strcmp($a['name'], $b['name']);
        });

        usort($landCards, fn ($a, $b) => strcmp($a['name'], $b['name']));

        $cmcDistribution = [];
        for ($i = 0; $i <= 6; $i++) {
            $key = (string) $i;
            $cmcDistribution[] = ['cmc' => $key, 'count' => $cmcBuckets[$key] ?? 0];
        }
        $cmcDistribution[] = ['cmc' => '7+', 'count' => $cmcBuckets['7+'] ?? 0];

        $typeDistribution = [];
        foreach (array_merge(self::CANONICAL_TYPES, ['Land']) as $type) {
            if (isset($typeCounts[$type])) {
                $typeDistribution[] = ['type' => $type, 'count' => $typeCounts[$type]];
            }
        }

        return [
            'nonLandCards' => $nonLandCards,
            'landCards' => $landCards,
            'sideboardCards' => $sideboardCards,
            'cmcDistribution' => $cmcDistribution,
            'typeDistribution' => $typeDistribution,
        ];
    }

    private static function normalizeType(string $raw): string
    {
        foreach (self::CANONICAL_TYPES as $canonical) {
            if (str_contains($raw, $canonical)) {
                return $canonical;
            }
        }
        if (str_contains($raw, 'Land')) {
            return 'Land';
        }

        return $raw;
    }

    private static function toBase64(Card $card): ?string
    {
        try {
            if ($card->local_image && Storage::disk('cards')->exists($card->local_image)) {
                $contents = Storage::disk('cards')->get($card->local_image);
            } elseif ($card->image) {
                $contents = file_get_contents($card->image);
            } else {
                return null;
            }
            if ($contents === false || $contents === null) {
                return null;
            }
            $source = $card->local_image ?? $card->image ?? '';
            $mime = 'image/jpeg';
            if (str_contains($source, '.png')) {
                $mime = 'image/png';
            } elseif (str_contains($source, '.webp')) {
                $mime = 'image/webp';
            }

            return 'data:'.$mime.';base64,'.base64_encode($contents);
        } catch (\Throwable) {
            return null;
        }
    }
}
