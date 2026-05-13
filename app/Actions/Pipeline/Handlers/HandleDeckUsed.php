<?php

namespace App\Actions\Pipeline\Handlers;

use App\Models\LogEvent;
use App\Support\PipelineContext;

/**
 * deck_used events surface when MTGO writes the "Deck Used in Game ID" log
 * line. Today the live pipeline does not derive any state from them — the
 * walker handles deck identification via game_management_json + game state
 * updates. This handler exists so the events get marked processed and don't
 * accumulate. Replace with a real handler if/when deck_used carries useful
 * info we can't get elsewhere.
 */
class HandleDeckUsed implements Handler
{
    public function handle(LogEvent $event, PipelineContext $context): void
    {
        // intentionally empty — events get marked processed by the walker
    }
}
