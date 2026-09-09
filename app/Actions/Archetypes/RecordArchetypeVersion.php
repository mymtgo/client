<?php

namespace App\Actions\Archetypes;

use App\Facades\AppSettings;
use Illuminate\Support\Facades\Cache;

class RecordArchetypeVersion
{
    public const REMOTE_CACHE_KEY = 'archetype_remote_version';

    /**
     * Remember the version of the archetype list that was just applied
     * locally. The remote marker is updated too, so the "out of date" banner
     * clears immediately instead of waiting for the next scheduled check.
     */
    public static function synced(?string $version): void
    {
        if ($version === null || $version === '') {
            return;
        }

        AppSettings::setArchetypeVersion($version);
        Cache::forever(self::REMOTE_CACHE_KEY, $version);
    }

    public static function remote(?string $version): void
    {
        if ($version === null || $version === '') {
            return;
        }

        Cache::forever(self::REMOTE_CACHE_KEY, $version);
    }

    public static function remoteVersion(): ?string
    {
        return Cache::get(self::REMOTE_CACHE_KEY);
    }

    /**
     * An update is only offered once the client has synced at least once
     * (a fresh install downloads archetypes itself) and the remote version
     * is actually known.
     */
    public static function updateAvailable(): bool
    {
        $local = AppSettings::archetypeVersion();
        $remote = self::remoteVersion();

        return $local !== null && $remote !== null && $local !== $remote;
    }
}
