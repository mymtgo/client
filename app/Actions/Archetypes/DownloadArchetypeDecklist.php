<?php

namespace App\Actions\Archetypes;

use App\Actions\RegisterDevice;
use App\Models\Archetype;
use App\Models\ArchetypeDeck;
use App\Models\Card;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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

        $decks = $response->json('decks', []);

        DB::transaction(function () use ($archetype, $decks) {
            foreach ($decks as $deckData) {
                self::upsertDeck($archetype, $deckData);
            }

            $archetype->update(['decklist_downloaded_at' => now()]);
        });
    }

    /**
     * @param  array{uuid: string, seen_count: int, cards: array<int, array<string, mixed>>}  $deckData
     */
    private static function upsertDeck(Archetype $archetype, array $deckData): void
    {
        $deck = ArchetypeDeck::updateOrCreate(
            ['uuid' => $deckData['uuid']],
            [
                'archetype_id' => $archetype->id,
                'seen_count' => $deckData['seen_count'] ?? 0,
                'last_synced_at' => now(),
            ]
        );

        $pivotData = [];
        foreach ($deckData['cards'] ?? [] as $cardData) {
            if (empty($cardData['oracle_id']) || empty($cardData['mtgo_id'])) {
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

        $deck->cards()->sync($pivotData);
    }

    private static function fetchFromApi(string $uuid): Response
    {
        return Http::mymtgoApi()->get('/api/archetypes/'.$uuid.'/decklists');
    }
}
