<?php

namespace App\Listeners\Pipeline;

use App\Events\CardRevealed;
use App\Jobs\EstimateArchetypeJob;
use Illuminate\Support\Facades\Cache;

class DetectArchetype
{
    public function handle(CardRevealed $event): void
    {
        $logEvent = $event->logEvent;
        $matchToken = $logEvent->match_token;

        if (! $matchToken) {
            return;
        }

        $data = json_decode($logEvent->raw_text, true);

        if (! is_array($data) || ! isset($data['card_name'], $data['player'])) {
            return;
        }

        $cacheKey = "archetype_detect:{$matchToken}:cards";
        $versionKey = "archetype_detect:{$matchToken}:version";

        $cards = Cache::get($cacheKey, []);
        $found = false;

        foreach ($cards as &$card) {
            if (strcasecmp($card['card_name'], $data['card_name']) === 0) {
                if ($card['quantity'] < 4) {
                    $card['quantity']++;
                }
                $found = true;
                break;
            }
        }
        unset($card);

        if (! $found) {
            $cards[] = [
                'card_name' => $data['card_name'],
                'quantity' => 1,
                'player' => $data['player'],
            ];
        }

        Cache::put($cacheKey, $cards, now()->addHour());

        // Use get+put instead of increment+put for cross-driver consistency
        $version = Cache::get($versionKey, 0) + 1;
        Cache::put($versionKey, $version, now()->addHour());

        EstimateArchetypeJob::dispatch($matchToken, $version)
            ->delay(now()->addSeconds(5));

        $logEvent->update(['processed_at' => now()]);
    }
}
