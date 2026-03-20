<?php

namespace App\Jobs;

use App\Actions\RegisterDevice;
use App\Events\ArchetypeEstimateUpdated;
use App\Models\Archetype;
use App\Models\MtgoMatch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Native\Desktop\Facades\Settings;

class EstimateArchetypeJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $matchToken,
        public int $version,
    ) {}

    public function handle(): void
    {
        $currentVersion = Cache::get("archetype_detect:{$this->matchToken}:version", 0);

        if ($this->version !== $currentVersion) {
            return;
        }

        $cards = Cache::get("archetype_detect:{$this->matchToken}:cards", []);

        if (empty($cards)) {
            return;
        }

        $match = MtgoMatch::where('token', $this->matchToken)->first();

        if (! $match) {
            return;
        }

        $playerName = Cache::get("archetype_detect:{$this->matchToken}:player", 'Unknown');

        try {
            $response = Http::withHeaders([
                'X-Device-Id' => Settings::get('device_id'),
                'X-Api-Key' => RegisterDevice::retrieveKey(),
            ])->post(config('mymtgo_api.url').'/api/archetypes/estimate', [
                'format' => strtolower($match->format),
                'cards' => $cards,
            ]);

            if (! $response->ok()) {
                return;
            }

            $archetypes = $response->json();

            if (! is_array($archetypes) || empty($archetypes)) {
                return;
            }

            $best = $archetypes[0];
            $archetype = Archetype::where('uuid', $best['uuid'])->first();

            if (! $archetype) {
                return;
            }

            ArchetypeEstimateUpdated::dispatch(
                matchToken: $this->matchToken,
                playerName: $playerName,
                archetypeName: $archetype->name,
                archetypeColorIdentity: $archetype->color_identity,
                confidence: (int) round(($best['confidence'] ?? 0) * 100),
                cardsSeen: count($cards),
            );
        } catch (\Throwable $e) {
            Log::channel('pipeline')->warning('EstimateArchetypeJob: API call failed', [
                'match_token' => $this->matchToken,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
