<?php

namespace App\Jobs;

use App\Actions\Archetypes\DownloadArchetypeDecklist;
use App\Facades\AppSettings;
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

    public function __construct(public int $archetypeId)
    {
        $this->onQueue('archetypes');
    }

    public function handle(): void
    {
        if (AppSettings::isOffline()) {
            return;
        }

        $archetype = Archetype::find($this->archetypeId);

        if (! $archetype || $archetype->is_fallback || $archetype->manual || $archetype->merged_into_id) {
            return;
        }

        // Matches DownloadArchetypeDecklists' degradation: a failure here (offline
        // mode toggled on mid-batch, API hiccup) shouldn't burn through tries=3 and
        // its [10, 60, 300]s backoff for every archetype in a refresh batch.
        try {
            DownloadArchetypeDecklist::run($archetype);
        } catch (Throwable $e) {
            Log::warning('RefreshArchetypeDecklist: failed', [
                'archetype_id' => $this->archetypeId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function failed(Throwable $e): void
    {
        Log::error('RefreshArchetypeDecklist failed', [
            'archetype_id' => $this->archetypeId,
            'exception' => $e->getMessage(),
        ]);
    }
}
