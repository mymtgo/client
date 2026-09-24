<?php

namespace App\Sidecar;

/**
 * Reads and writes the download state file. Writes go to a temp file and
 * are renamed into place so a reader never sees half a file.
 */
final class SidecarDownloadStore
{
    public static function read(): SidecarDownloadState
    {
        $idle = SidecarDownloadState::idle((string) config('sidecar.version'));
        $path = SidecarPaths::downloadStateFile();

        if (! is_file($path)) {
            return $idle;
        }

        $raw = @file_get_contents($path);

        if ($raw === false || $raw === '') {
            return $idle;
        }

        try {
            $json = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $idle;
        }

        return is_array($json) ? SidecarDownloadState::fromArray($json) : $idle;
    }

    public static function write(SidecarDownloadState $state): void
    {
        $dir = SidecarPaths::binDirectory();

        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $path = SidecarPaths::downloadStateFile();
        $tmp = $path.'.'.bin2hex(random_bytes(4)).'.tmp';

        file_put_contents($tmp, json_encode($state->toArray(), JSON_THROW_ON_ERROR));

        if (! @rename($tmp, $path)) {
            @unlink($tmp);
        }
    }

    /**
     * Write only while $runId still owns the file. A Retry or a later boot
     * starts a new run; progress from the old one must not overwrite it.
     * The read-then-write is not locked: the worst case is one stray
     * progress write, which the next write from the owning run replaces.
     */
    public static function writeForRun(string $runId, SidecarDownloadState $state): bool
    {
        if (self::read()->runId !== $runId) {
            return false;
        }

        self::write($state);

        return true;
    }
}
