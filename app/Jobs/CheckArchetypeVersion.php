<?php

namespace App\Jobs;

use App\Actions\Archetypes\RecordArchetypeVersion;
use App\Facades\AppSettings;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;

/**
 * Cheap probe of the API's archetype version. Never writes archetypes: the
 * refresh itself stays a user-triggered action, this only decides whether to
 * show the "archetypes out of date" banner.
 */
class CheckArchetypeVersion implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function handle(): void
    {
        if (AppSettings::isOffline()) {
            return;
        }

        $version = Http::mymtgoApi()->throw()->get('/api/archetypes/version')->json('version');

        RecordArchetypeVersion::remote(is_scalar($version) ? (string) $version : null);
    }
}
