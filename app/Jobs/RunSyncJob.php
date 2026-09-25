<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Sync\SyncRunner;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Runs the sync engine (manifest, push, pull, per type) on the `sync`
 * queue. `SyncRunner::run()` handles every failure mode itself (a
 * `NotLinkedException` aborts silently, anything else logs and aborts), so
 * this job stays a thin delegate with no failure handling of its own.
 *
 * `$full` only forces every type into a full reconcile; it is not the only
 * way a type gets one. `SyncRunner` also escalates a single type to a full
 * reconcile on its own, with $full left false, whenever that type's stored
 * `sync_state.canonical_version` no longer matches the app's current one
 * (a bundle format bump), so an ordinary scheduled dispatch of this job
 * self-heals a stale format without ever needing `--full`.
 *
 * `uniqueId()` is keyed to the mode (`run-sync:full` vs
 * `run-sync:incremental`) rather than a single constant. A shared constant
 * would let a scheduled incremental dispatch silently swallow a
 * user-triggered "sync now" or `--full` dispatch for up to an hour, since
 * `ShouldBeUnique` drops any duplicate dispatch while the lock is held.
 * Keying by mode still lets a full and an incremental run race each other
 * onto the `sync` queue, but the worker only runs one job at a time per
 * queue, so the two serialize on the single `sync` worker rather than
 * overlapping, which is acceptable.
 *
 * The lock is released when the job starts, not when it finishes. A match
 * that completes while a run is in flight then queues a follow-up run
 * instead of being swallowed until the half-hourly schedule; the single
 * `sync` worker still keeps the two from overlapping.
 */
class RunSyncJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $uniqueFor = 3600;

    public function __construct(private readonly bool $full = false)
    {
        $this->onQueue('sync');
    }

    public function uniqueId(): string
    {
        return $this->full ? 'run-sync:full' : 'run-sync:incremental';
    }

    public function handle(SyncRunner $runner): void
    {
        $runner->run($this->full);
    }
}
