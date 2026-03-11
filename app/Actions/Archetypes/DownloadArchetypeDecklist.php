<?php

namespace App\Actions\Archetypes;

use App\Actions\RegisterDevice;
use App\Models\Archetype;
use App\Models\Card;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Native\Desktop\Facades\Settings;

class DownloadArchetypeDecklist
{
    /**
     * @throws \RuntimeException
     */
    public static function run(Archetype $archetype): void
    {
        $response = self::fetchFromApi($archetype->uuid);

        if ($response->status() === 401) {
            RegisterDevice::run();
            $response = self::fetchFromApi($archetype->uuid);
        }

        if (! $response->successful()) {
            Log::error('DownloadArchetypeDecklist: API failure', [
                'archetype' => $archetype->uuid,
                'status' => $response->status(),
            ]);
            throw new \RuntimeException('Failed to download decklist from API.');
        }

        $cards = $response->json('cards', []);
        $pivotData = [];

        foreach ($cards as $cardData) {
            if (empty($cardData['oracle_id'])) {
                continue;
            }

            $card = Card::updateOrCreate(
                ['oracle_id' => $cardData['oracle_id']],
                [
                    'mtgo_id' => $cardData['mtgo_id'],
                    'name' => $cardData['name'],
                    'type' => $cardData['type'],
                    'color_identity' => $cardData['color_identity'] ?? null,
                    'image' => $cardData['image'] ?? null,
                ]
            );

            $pivotData[$card->id] = [
                'quantity' => $cardData['quantity'],
                'sideboard' => $cardData['sideboard'] ?? false,
            ];
        }

        $archetype->cards()->sync($pivotData);
        $archetype->update(['decklist_downloaded_at' => now()]);
    }

    private static function fetchFromApi(string $uuid): \Illuminate\Http\Client\Response
    {
        return Http::withHeaders([
            'X-Device-Id' => Settings::get('device_id'),
            'X-Api-Key' => RegisterDevice::retrieveKey(),
        ])->get(config('mymtgo_api.url').'/api/archetypes/'.$uuid.'/decklist');
    }
}
