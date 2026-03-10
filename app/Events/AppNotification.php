<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Foundation\Events\Dispatchable;

class AppNotification
{
    use Dispatchable;

    public function __construct(
        public string $type,
        public string $title,
        public string $message,
        public ?string $route = null,
    ) {}

    /**
     * @return array<int, Channel|string>
     */
    public function broadcastOn(): array
    {
        return ['nativephp'];
    }
}
