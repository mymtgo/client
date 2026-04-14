<?php

namespace App\Actions\Archetypes;

use App\Models\Card;
use Illuminate\Support\Facades\Http;

class ResolveCardsFromDek
{
    private const COLOR_ORDER = ['W', 'U', 'B', 'R', 'G'];

    /**
     * Resolve MTGO card IDs into full card data and derive colour identity.
     *
     * @param  array<int, array{mtgo_id: int, quantity: int, sideboard: bool}>  $parsedCards
     * @return array{cards: array, color_identity: string|null}
     */
    public static function run(array $parsedCards): array
    {
        $mtgoIds = array_column($parsedCards, 'mtgo_id');
        $quantityMap = collect($parsedCards)->keyBy('mtgo_id');

        $response = Http::mymtgoApi()->post('/api/cards/resolve', [
            'mtgo_ids' => $mtgoIds,
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Failed to resolve cards from API.');
        }

        $apiCards = $response->json('cards', []);
        $colors = collect();
        $resolvedCards = [];

        foreach ($apiCards as $cardData) {
            $entry = $quantityMap->get($cardData['mtgo_id']);

            if (! $entry) {
                continue;
            }

            if (! empty($cardData['oracle_id'])) {
                Card::updateOrCreate(
                    ['oracle_id' => $cardData['oracle_id']],
                    [
                        'mtgo_id' => $cardData['mtgo_id'],
                        'name' => $cardData['name'],
                        'type' => $cardData['type'],
                        'color_identity' => $cardData['identity'] ?? null,
                        'image' => $cardData['image'] ?? null,
                        'art_crop' => $cardData['art_crop'] ?? null,
                        'cmc' => $cardData['cmc'] ?? null,
                    ]
                );
            }

            if (! empty($cardData['identity']) && ! str_contains($cardData['type'] ?? '', 'Land')) {
                foreach (explode(',', $cardData['identity']) as $color) {
                    $colors->push(trim($color));
                }
            }

            $resolvedCards[] = [
                'mtgo_id' => $cardData['mtgo_id'],
                'oracle_id' => $cardData['oracle_id'] ?? null,
                'name' => $cardData['name'],
                'type' => $cardData['type'],
                'image' => $cardData['image'] ?? null,
                'art_crop' => $cardData['art_crop'] ?? null,
                'cmc' => $cardData['cmc'] ?? null,
                'identity' => $cardData['identity'] ?? null,
                'quantity' => $entry['quantity'],
                'sideboard' => $entry['sideboard'],
            ];
        }

        $sortedColors = $colors->unique()
            ->sort(fn ($a, $b) => array_search($a, self::COLOR_ORDER) - array_search($b, self::COLOR_ORDER))
            ->values()
            ->implode(',');

        return [
            'cards' => $resolvedCards,
            'color_identity' => $sortedColors ?: null,
        ];
    }
}
