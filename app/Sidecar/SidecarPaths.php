<?php

namespace App\Sidecar;

use App\Facades\AppSettings;

class SidecarPaths
{
    public const EXE_PREFIX = 'mymtgo-helper-';

    public static function directory(): string
    {
        return rtrim(AppSettings::sidecarDirectory(), '/\\');
    }

    public static function statusFile(): string
    {
        return self::directory().DIRECTORY_SEPARATOR.'status.json';
    }

    /** The helper only runs on Windows; everywhere else the app is log-only. */
    public static function supported(): bool
    {
        return config('sidecar.platform') === 'Windows';
    }

    /** Where downloaded helper exes and the download state live, inside the user's appData. */
    public static function binDirectory(): string
    {
        $configured = config('sidecar.bin_directory');

        return rtrim(is_string($configured) && $configured !== '' ? $configured : storage_path('app/sidecar-bin'), '/\\');
    }

    /** Final path of the pinned helper version, whether or not it has been downloaded. */
    public static function pinnedExe(): string
    {
        return self::binDirectory().DIRECTORY_SEPARATOR.self::EXE_PREFIX.config('sidecar.version').'.exe';
    }

    public static function downloadStateFile(): string
    {
        return self::binDirectory().DIRECTORY_SEPARATOR.'download.json';
    }

    /**
     * Absolute path of the helper exe to run, or null when none is present
     * or the platform cannot run one. Null is the normal state on macOS and
     * before the first download; everything downstream treats it as log-only.
     *
     * The pinned file is trusted without re-hashing: it only reaches that
     * name after DownloadSidecarJob verified it, and anything able to swap
     * it afterwards already runs as this user.
     */
    public static function exe(): ?string
    {
        if (! self::supported()) {
            return null;
        }

        $override = config('sidecar.exe_override');

        if (app()->isLocal() && is_string($override) && $override !== '' && is_file($override)) {
            return $override;
        }

        $pinned = self::pinnedExe();

        return is_file($pinned) ? $pinned : null;
    }

    /** @return list<string> downloaded exes for versions other than the pinned one */
    public static function staleExes(): array
    {
        $files = glob(self::binDirectory().DIRECTORY_SEPARATOR.self::EXE_PREFIX.'*.exe') ?: [];

        // Compare names, not paths: glob may spell the directory with
        // different separators than storage_path() on Windows.
        $pinned = basename(self::pinnedExe());

        return array_values(array_filter($files, fn (string $file) => basename($file) !== $pinned));
    }

    /** @return list<string> absolute paths, oldest first by name */
    public static function eventFiles(): array
    {
        $files = glob(self::directory().DIRECTORY_SEPARATOR.'events-*.ndjson') ?: [];
        sort($files);

        return $files;
    }
}
