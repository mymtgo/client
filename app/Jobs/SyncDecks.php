<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncDecks implements ShouldQueue
{
    use Queueable;

    /** Filesystem/XML errors are usually transient — retry twice. */
    public int $tries = 3;

    /** @var int[] */
    public array $backoff = [5, 30];

    public function handle(): void
    {
        \App\Actions\Decks\SyncDecks::run();
    }
}
