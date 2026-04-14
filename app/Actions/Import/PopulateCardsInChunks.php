<?php

namespace App\Actions\Import;

use App\Actions\RegisterDevice;
use App\Models\Card;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Native\Desktop\Facades\Settings;

class PopulateCardsInChunks
{
    /**
     * Populate card data from the API in chunks of 200 to avoid request size limits.
     */
    public static function run(): void
    {
        $unpopulated = Card::whereNull('name')->pluck('mtgo_id');

        if ($unpopulated->isEmpty()) {
            return;
        }

        $deviceId = Settings::get('device_id');
        $apiKey = RegisterDevice::retrieveKey();

        if (! $deviceId || ! $apiKey) {
            Log::channel('pipeline')->warning('PopulateCardsInChunks: cannot populate cards — no device registration');

            return;
        }

        foreach ($unpopulated->chunk(200) as $chunk) {
            try {
                $response = Http::withHeaders([
                    'X-Device-Id' => $deviceId,
                    'X-Api-Key' => $apiKey,
                ])->post(config('mymtgo_api.url').'/api/cards', [
                    'ids' => $chunk->values(),
                    'tokens' => [],
                ]);

                if (! $response->successful()) {
                    Log::channel('pipeline')->warning('PopulateCardsInChunks: card populate chunk failed', [
                        'status' => $response->status(),
                    ]);

                    continue;
                }

                $cardsResponse = collect($response->json());

                foreach ($chunk as $mtgoId) {
                    $cardData = $cardsResponse->first(
                        fn ($data) => ($data['value'] ?? null) == $mtgoId
                    );

                    if (! $cardData) {
                        continue;
                    }

                    Card::where('mtgo_id', $mtgoId)->update([
                        'scryfall_id' => $cardData['scryfall_id'] ?? null,
                        'oracle_id' => $cardData['oracle_id'] ?? null,
                        'name' => $cardData['name'] ?? null,
                        'type' => $cardData['type'] ?? null,
                        'sub_type' => $cardData['sub_type'] ?? null,
                        'rarity' => $cardData['rarity'] ?? null,
                        'color_identity' => isset($cardData['color_identity'])
                            ? collect(explode(',', $cardData['color_identity']))->map(fn ($c) => $c ?: 'C')->join(',')
                            : null,
                        'image' => $cardData['image'] ?? null,
                    ]);
                }
            } catch (\Throwable $e) {
                Log::channel('pipeline')->warning('PopulateCardsInChunks: card populate chunk exception', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
