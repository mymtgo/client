<?php

namespace App\Jobs;

use App\Actions\Archetypes\DownloadArchetypeDecklist;
use App\Models\Archetype;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class DownloadArchetypeDecklists implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(
        public readonly int $archetypeId,
    ) {}

    public function handle(): void
    {
        $archetype = Archetype::where('id', $this->archetypeId)
            ->where(fn ($q) => $q->whereNull('decklist_downloaded_at')->orWhere('decklist_downloaded_at', '<', now()->subWeek()))
            ->first();

        if (! $archetype) {
            return;
        }

        try {
            DownloadArchetypeDecklist::run($archetype);
        } catch (\Throwable $e) {
            Log::warning('DownloadArchetypeDecklists: failed', [
                'archetype' => $archetype->uuid,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
