<?php

declare(strict_types=1);

namespace App\Services\Sync;

use App\Models\Archetype;

/**
 * Resolves a bundle's archetype uuid to this device's local archetypes.id.
 *
 * Bundles never carry archetype rows (the API owns them), only uuids. A uuid
 * the local cache lacks gets a stub row, mirroring the convention
 * DownloadArchetypes (the daily refresh) expects: manual and is_fallback
 * stay false so the refresh's updateOrCreate does not skip it, and its next
 * run replaces the placeholder name (the uuid itself) with the real one.
 * `incomplete` is deliberately left at its default: that flag is scoped to
 * manually built archetypes from the decklist UI and is never reset by the
 * refresh, so setting it here would mislabel the row forever.
 */
class LocalArchetypeId
{
    public static function for(string $uuid): int
    {
        return Archetype::firstOrCreate(
            ['uuid' => $uuid],
            ['name' => $uuid, 'manual' => false, 'is_fallback' => false],
        )->id;
    }
}
