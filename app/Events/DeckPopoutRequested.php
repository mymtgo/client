<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

class DeckPopoutRequested
{
    use Dispatchable;

    public function __construct(public int $deckId) {}

    /**
     * @return array<int, string>
     */
    public function broadcastOn(): array
    {
        return ['nativephp'];
    }
}
