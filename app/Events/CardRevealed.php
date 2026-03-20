<?php

namespace App\Events;

use App\Models\LogEvent;
use Illuminate\Foundation\Events\Dispatchable;

class CardRevealed
{
    use Dispatchable;

    public function __construct(public LogEvent $logEvent) {}
}
