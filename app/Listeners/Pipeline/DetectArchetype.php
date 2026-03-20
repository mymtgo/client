<?php

namespace App\Listeners\Pipeline;

use App\Actions\Util\ExtractJson;
use App\Events\GameStateChanged;
use App\Facades\Mtgo;
use App\Jobs\EstimateArchetypeJob;
use Illuminate\Support\Facades\Cache;

class DetectArchetype
{
    public function handle(GameStateChanged $event): void
    {
        $logEvent = $event->logEvent;
        $matchToken = $logEvent->match_token;

        if (! $matchToken) {
            return;
        }

        $localPlayer = Mtgo::getUsername();
        if (! $localPlayer) {
            return;
        }

        // Parse the game state JSON
        $content = ExtractJson::run($logEvent->raw_text)->first();
        if (! is_array($content)) {
            return;
        }

        $players = $content['Players'] ?? [];
        $cards = $content['Cards'] ?? [];

        if (empty($players) || empty($cards)) {
            return;
        }

        // Find the opponent's player instance ID
        $opponentId = null;
        $opponentName = null;
        foreach ($players as $player) {
            if ($player['Name'] !== $localPlayer) {
                $opponentId = $player['Id'];
                $opponentName = $player['Name'];
                break;
            }
        }

        if (! $opponentId) {
            return;
        }

        // Extract opponent's cards with mtgo_ids directly
        $opponentCards = collect($cards)
            ->filter(fn ($card) => $card['Owner'] === $opponentId)
            ->groupBy('CatalogID')
            ->map(fn ($group) => [
                'mtgo_id' => $group[0]['CatalogID'],
                'quantity' => min($group->count(), 4),
            ])
            ->values()
            ->toArray();

        if (empty($opponentCards)) {
            return;
        }

        $cacheKey = "archetype_detect:{$matchToken}:cards";
        $versionKey = "archetype_detect:{$matchToken}:version";
        $playerKey = "archetype_detect:{$matchToken}:player";

        // Replace cache with latest full card state (game state events contain ALL cards seen so far)
        Cache::put($cacheKey, $opponentCards, now()->addHour());
        Cache::put($playerKey, $opponentName, now()->addHour());

        $version = Cache::get($versionKey, 0) + 1;
        Cache::put($versionKey, $version, now()->addHour());

        EstimateArchetypeJob::dispatch($matchToken, $version)
            ->delay(now()->addSeconds(5));
    }
}
