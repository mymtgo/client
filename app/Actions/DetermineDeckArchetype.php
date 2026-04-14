<?php

namespace App\Actions;

use App\Actions\Archetypes\EstimateArchetypeLocally;
use App\Models\Archetype;
use App\Models\ArchetypeMatchAttempt;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DetermineDeckArchetype
{
    /** Minimum local confidence to skip the API call. */
    private const LOCAL_CONFIDENCE_THRESHOLD = 0.5;

    public static function run(Collection $cards, string $format, ?int $matchId = null, ?int $playerId = null): ?array
    {
        $localResult = EstimateArchetypeLocally::run($cards, $format);

        if ($localResult && $localResult['confidence'] >= self::LOCAL_CONFIDENCE_THRESHOLD) {
            self::logAttempt(
                matchId: $matchId,
                playerId: $playerId,
                format: $format,
                cards: $cards,
                result: $localResult,
                source: 'local',
            );

            return $localResult;
        }

        return self::estimateViaApi($cards, $format, $matchId, $playerId);
    }

    private static function estimateViaApi(Collection $cards, string $format, ?int $matchId, ?int $playerId): ?array
    {
        $payload = [
            'format' => $format,
            'cards' => $cards->values(),
        ];

        $response = Http::mymtgoApi()->post('/api/archetypes/estimate', $payload);

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
            Log::warning('Failed to log archetype attempt: '.$e->getMessage());
        }

        return $result;
    }

    private static function logAttempt(
        ?int $matchId,
        ?int $playerId,
        string $format,
        Collection $cards,
        array $result,
        string $source,
    ): void {
        try {
            ArchetypeMatchAttempt::create([
                'match_id' => $matchId,
                'player_id' => $playerId,
                'format' => $format,
                'payload' => ['format' => $format, 'cards' => $cards->values(), 'source' => $source],
                'status_code' => null,
                'response' => ['source' => $source],
                'archetype_id' => $result['archetype_id'],
                'confidence' => $result['confidence'],
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to log archetype attempt: '.$e->getMessage());
        }
    }
}
