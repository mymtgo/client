<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Foundation\Events\Dispatchable;

class DraftPickCommitted
{
    use Dispatchable;

    public function __construct(public int $draftId, public int $ordinal) {}

    /**
     * @return array<int, Channel|string>
     */
    public function broadcastOn(): array
    {
        return ['nativephp'];
    }
}
