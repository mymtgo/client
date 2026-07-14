<?php

namespace App\Events\Auth;

use Illuminate\Broadcasting\Channel;
use Illuminate\Foundation\Events\Dispatchable;

class AuthCallbackFailed
{
    use Dispatchable;

    /**
     * @param  'cancelled'|'failed'  $reason
     */
    public function __construct(public string $reason) {}

    /**
     * @return array<int, Channel|string>
     */
    public function broadcastOn(): array
    {
        return ['nativephp'];
    }
}
