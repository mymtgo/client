<?php

namespace App\Jobs;

use App\Models\Archetype;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;

class DownloadArchetypes implements ShouldQueue
{
    use Queueable;

    /** Retry up to 3 times before moving to failed_jobs. */
    public int $tries = 3;

    /** @var int[] Seconds between retries */
    public array $backoff = [10, 60, 300];

    public function handle(): void
    {
        $response = Http::mymtgoApi()->get('/api/archetypes');

        if (! $response->successful()) {
            throw new \RuntimeException("DownloadArchetypes: API returned {$response->status()}");
        }

        foreach ($response->json() as $archetype) {
            Archetype::updateOrCreate(
                ['uuid' => $archetype['uuid']],
                [
                    'name' => $archetype['name'],
                    'format' => strtolower($archetype['format']),
                    'color_identity' => $archetype['colorIdentity'] ?? null,
                ],
            );
        }
    }
}
