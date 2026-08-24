<?php

namespace App\Updates;

use App\Facades\AppSettings;
use Illuminate\Support\Facades\Storage;

class RenameShareStatsToOfflineMode extends AppUpdate
{
    /**
     * Invert the retired `share_stats` opt-in into the `offline_mode` opt-out.
     *
     * A user who turned sharing off gets offline mode on. An absent key means
     * the user never expressed a preference, so they default to online, which
     * matches the new-install default.
     *
     * Guarded on `settings.json` existing first. `MigrateSettingsToJson` can
     * defer itself to a later boot when every NativePHP Settings read fails
     * (see its own docblock), in which case `settings.json` is not created
     * yet. `RunAppUpdates` has no dependency on that file and would otherwise
     * run this update anyway, read an absent `share_stats`, default to
     * online, and record itself as complete — permanently losing the user's
     * real opt-out once the deferred migration eventually succeeds. Throwing
     * instead of defaulting makes `RunAppUpdates` log the failure without
     * recording completion, so this update retries on the next boot until the
     * legacy value is actually readable.
     */
    public function run(): void
    {
        if (! Storage::disk()->exists('settings.json')) {
            throw new \RuntimeException('RenameShareStatsToOfflineMode: settings.json does not exist yet, deferring until MigrateSettingsToJson succeeds.');
        }

        $shareStats = AppSettings::get('share_stats');

        if ($shareStats !== null) {
            AppSettings::setOffline(! $shareStats);
        } elseif (AppSettings::get('offline_mode') === null) {
            AppSettings::setOffline(false);
        }

        AppSettings::forget('share_stats');
    }
}
