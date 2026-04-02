<?php

namespace App\Actions\Archetypes;

use App\Models\Archetype;
use App\Models\Card;
use Illuminate\Support\Str;
use Native\Desktop\Facades\Settings;

class StoreManualArchetype
{
    /**
     * @param  array<int, array{mtgo_id: int, oracle_id: string|null, quantity: int, sideboard: bool}>  $resolvedCards
     */
    public static function run(string $name, string $format, ?string $colorIdentity, array $resolvedCards): Archetype
    {
        $deviceId = Settings::get('device_id', '00000000');
        $prefix = substr($deviceId, 0, 8);
        $uuid = $prefix.'-'.Str::uuid();

        $archetype = Archetype::create([
            'uuid' => $uuid,
            'name' => $name,
            'format' => strtolower($format),
            'color_identity' => $colorIdentity,
            'manual' => true,
            'decklist_downloaded_at' => now(),
        ]);

        $pivotData = [];

        foreach ($resolvedCards as $cardData) {
            if (empty($cardData['oracle_id'])) {
                continue;
            }

            $card = Card::where('oracle_id', $cardData['oracle_id'])->first();

            if (! $card) {
                continue;
            }

            $pivotData[$card->id] = [
                'quantity' => $cardData['quantity'],
                'sideboard' => $cardData['sideboard'],
            ];
        }

        $archetype->cards()->sync($pivotData);

        return $archetype->load('cards');
    }
}
