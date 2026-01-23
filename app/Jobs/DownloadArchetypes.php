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
            $model = Archetype::where('uuid', $archetype['uuid'])->first() ?: new Archetype([
                'name' => $archetype['name'],
                'uuid' => $archetype['uuid'],
                'format' => strtolower($archetype['format']),
            ]);

            $model->save();

            $model->update([
                'color_identity' => $archetype['colorIdentity'],
            ]);
        }
    }
}
