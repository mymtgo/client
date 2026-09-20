<?php

namespace App\Actions\Archetypes;

use App\Exceptions\DuplicateVariantException;
use App\Models\Archetype;
use App\Models\ArchetypeDeck;
use App\Models\Card;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AddArchetypeVariant
{
    /**
     * @param  array<int, array{oracle_id: string|null, mtgo_id: int, quantity: int, sideboard: bool}>  $resolvedCards
     */
    public static function run(Archetype $archetype, array $resolvedCards): ArchetypeDeck
    {
        return DB::transaction(function () use ($archetype, $resolvedCards) {
            $pivotData = self::resolvePivotData($resolvedCards);
            $newHash = self::hashPivot($pivotData);

            $archetype->load(['decks.cards']);
            foreach ($archetype->decks as $existing) {
                if (self::hashDeck($existing) === $newHash) {
                    throw new DuplicateVariantException;
                }
            }

            /** @var ArchetypeDeck $deck */
            $deck = $archetype->decks()->create([
                'uuid' => (string) Str::uuid(),
                'seen_count' => 1,
                'last_synced_at' => now(),
            ]);

            $deck->syncCardRows($pivotData);

            return $deck->refresh();
        });
    }

    /**
     * A card may appear once in the maindeck and again in the sideboard, so rows are
     * keyed by card and board rather than by card alone.
     *
     * @param  array<int, array{oracle_id: string|null, mtgo_id: int, quantity: int, sideboard: bool}>  $resolvedCards
     * @return array<string, array{card_id: int, quantity: int, sideboard: bool}>
     */
    private static function resolvePivotData(array $resolvedCards): array
    {
        $pivotData = [];

        foreach ($resolvedCards as $cardData) {
            if (empty($cardData['oracle_id'])) {
                continue;
            }

            $card = Card::where('oracle_id', $cardData['oracle_id'])->first();

            if (! $card) {
                continue;
            }

            $sideboard = (bool) $cardData['sideboard'];

            $pivotData[$card->id.':'.(int) $sideboard] = [
                'card_id' => $card->id,
                'quantity' => $cardData['quantity'],
                'sideboard' => $sideboard,
            ];
        }

        return $pivotData;
    }

    /**
     * @param  array<string, array{card_id: int, quantity: int, sideboard: bool}>  $pivotData
     */
    private static function hashPivot(array $pivotData): string
    {
        $rows = [];
        foreach ($pivotData as $row) {
            $rows[] = $row['card_id'].':'.((int) $row['sideboard']).':'.$row['quantity'];
        }
        sort($rows);

        return sha1(implode('|', $rows));
    }

    private static function hashDeck(ArchetypeDeck $deck): string
    {
        $pivotData = [];
        foreach ($deck->cards as $card) {
            $sideboard = (bool) $card->pivot->sideboard;

            $pivotData[$card->id.':'.(int) $sideboard] = [
                'card_id' => $card->id,
                'quantity' => $card->pivot->quantity,
                'sideboard' => $sideboard,
            ];
        }

        return self::hashPivot($pivotData);
    }
}
