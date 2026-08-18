<?php

namespace App\Jobs;

use App\Facades\AppSettings;
use App\Models\Archetype;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;

class RefreshArchetypes implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct()
    {
        $this->onQueue('archetypes');
    }

    public function handle(): void
    {
        // Guard against re-entry: a manual refresh can be triggered again while
        // the long-lived decklist batch from a previous run is still in flight.
        // Without this check each trigger would spawn another full 700+ child
        // batch. See memory/refresh_archetypes_re_entry_trap.md.
        if (AppSettings::archetypesRefreshInProgress()) {
            return;
        }

        $archetypeIds = Archetype::query()
            ->where('is_fallback', false)
            ->where('manual', false)
            ->whereNull('merged_into_id')
            ->whereNotNull('format')
            ->pluck('id')
            ->all();

        if (empty($archetypeIds)) {
            AppSettings::setArchetypesLastRefreshedAt(now()->toIso8601String());
            self::bumpCacheVersion();

            return;
        }

        $jobs = collect($archetypeIds)
            ->map(fn (int $id, int $index) => (new RefreshArchetypeDecklist($id))->delay(now()->addSeconds($index * 2)))
            ->all();

        AppSettings::setArchetypesRefreshInProgress(true);

        Bus::batch($jobs)
            ->name('archetypes:refresh:'.now()->toDateString())
            ->allowFailures()
            ->finally(function () {
                AppSettings::setArchetypesLastRefreshedAt(now()->toIso8601String());
                AppSettings::setArchetypesRefreshInProgress(false);
                self::bumpCacheVersion();
            })
            ->dispatch();
    }

    private static function bumpCacheVersion(): void
    {
        Cache::add('archetypes:cache_version', 0);
        Cache::increment('archetypes:cache_version');
    }
}
