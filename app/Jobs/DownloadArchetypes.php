<?php

namespace App\Jobs;

use App\Models\Archetype;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;

class DownloadArchetypes implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $response = Http::withoutVerifying()->get('https://api.test/api/archetypes');

        foreach ($response->json() as $archetype) {
            Archetype::firstOrCreate([
                'name' => $archetype['name'],
                'format' => $archetype['format'],
            ], [
                'uuid' => $archetype['uuid'],
                'color_identity' => $archetype['colorIdentity'],
            ]);
        }
    }
}
