<?php

namespace App\Actions;

use App\Models\Archetype;
use App\Models\ArchetypeMatchAttempt;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Native\Desktop\Facades\Settings;

class DetermineDeckArchetype
{
    public static function run(Collection $cards, string $format, ?int $matchId = null, ?int $playerId = null): ?array
    {
        $payload = [
            'format' => $format,
            'cards' => $cards->values(),
        ];

        $response = Http::withHeaders([
            'X-Device-Id' => Settings::get('device_id'),
            'X-Api-Key' => RegisterDevice::retrieveKey(),
        ])->post(config('mymtgo_api.url').'/api/archetypes/estimate', $payload);

        $archetypes = $response->json();

        $result = null;

        if ($response->ok() && is_array($archetypes) && count($archetypes)) {
            $archetype = $archetypes[0];
            $archetypeModel = Archetype::where('uuid', $archetype['uuid'])->first();

            if ($archetypeModel) {
                $result = [
                    'archetype_id' => $archetypeModel->id,
                    'confidence' => $archetype['confidence'],
                ];
            }
        }

        try {
            ArchetypeMatchAttempt::create([
                'match_id' => $matchId,
                'player_id' => $playerId,
                'format' => $format,
                'payload' => $payload,
                'status_code' => $response->status(),
                'response' => $archetypes,
                'archetype_id' => $result['archetype_id'] ?? null,
                'confidence' => $result['confidence'] ?? null,
            ]);
        } catch (\Throwable $e) {
            \Log::warning('Failed to log archetype attempt: '.$e->getMessage());
        }

        return $result;
    }
}
