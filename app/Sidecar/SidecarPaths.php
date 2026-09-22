<?php

namespace App\Sidecar;

use App\Facades\AppSettings;

class SidecarPaths
{
    public const EXE_RELATIVE = 'resources/sidecar/mymtgo-helper.exe';

    public static function directory(): string
    {
        return rtrim(AppSettings::sidecarDirectory(), '/\\');
    }

    public static function statusFile(): string
    {
        return self::directory().DIRECTORY_SEPARATOR.'status.json';
    }

    /**
     * Absolute path of the bundled sidecar exe, or null when this build or
     * platform does not ship one. Null is the normal state on macOS and in
     * dev; everything downstream treats it as "log-only".
     */
    public static function exe(): ?string
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return null;
        }

        $path = base_path(self::EXE_RELATIVE);

        return is_file($path) ? $path : null;
    }

    /** @return list<string> absolute paths, oldest first by name */
    public static function eventFiles(): array
    {
        $files = glob(self::directory().DIRECTORY_SEPARATOR.'events-*.ndjson') ?: [];
        sort($files);

        return $files;
    }
}
