<?php

namespace App\Jobs;

use App\Actions\Archetypes\DownloadArchetypeDecklist;
use App\Models\Archetype;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class RefreshArchetypeDecklist implements ShouldQueue
{
    use Batchable, Queueable;

    public int $tries = 3;

    /** @var int[] */
    public array $backoff = [10, 60, 300];

    public function __construct(public int $archetypeId) {}

    public function handle(): void
    {
        $archetype = Archetype::find($this->archetypeId);

        if (! $archetype || $archetype->is_fallback || $archetype->manual || $archetype->merged_into_id) {
            return;
        }

        DownloadArchetypeDecklist::run($archetype);
    }

    public function failed(Throwable $e): void
    {
        Log::error('RefreshArchetypeDecklist failed', [
            'archetype_id' => $this->archetypeId,
            'exception' => $e->getMessage(),
        ]);
    }
}
