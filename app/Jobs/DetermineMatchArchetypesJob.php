<?php

namespace App\Jobs;

use App\Actions\DetermineMatchArchetypes;
use App\Models\MtgoMatch;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class DetermineMatchArchetypesJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int> */
    public array $backoff = [2, 5];

    public int $uniqueFor = 600;

    public function __construct(
        public int $matchId,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->matchId;
    }

    public function handle(): void
    {
        $match = MtgoMatch::with('games')->find($this->matchId);

        if (! $match) {
            $this->clearQueuedFlag();

            return;
        }

        try {
            DetermineMatchArchetypes::run($match);
        } finally {
            $this->clearQueuedFlag();
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->clearQueuedFlag();
    }

    protected function clearQueuedFlag(): void
    {
        MtgoMatch::query()
            ->whereKey($this->matchId)
            ->whereNotNull('archetype_detection_queued_at')
            ->update(['archetype_detection_queued_at' => null]);
    }
}
