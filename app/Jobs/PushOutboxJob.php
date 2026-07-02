<?php

namespace App\Jobs;

use App\Actions\Outbox\PushMatch;
use App\Models\Outbox;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Drain the outbox: push every pending row to the sink. ShouldBeUnique so
 * overlapping triggers (debounce + periodic + app-close) collapse into one
 * in-flight drain.
 */
class PushOutboxJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function handle(PushMatch $push): void
    {
        Outbox::query()
            ->pending()
            ->orderBy('updated_at')
            ->each(fn (Outbox $row) => $push->run($row));
    }
}
