<?php

namespace App\Jobs;

use App\Actions\Overlay\PublishOverlayState;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * One push of the league card to the hosted overlay. Unique until it starts,
 * so a burst of changes leaves one pending job, and that job builds
 * the state when it runs, so it always sends the latest.
 */
class PublishOverlayStateJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** Bounds the lock: a lost pending job must not swallow every dispatch for a day. */
    public int $uniqueFor = 60;

    public function __construct()
    {
        $this->onQueue('overlay');
    }

    public function handle(): void
    {
        PublishOverlayState::run();
    }
}
