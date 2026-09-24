<?php

namespace App\Actions\Sidecar;

use App\Sidecar\SidecarPaths;
use App\Sidecar\SidecarStatus;

class ReadSidecarStatus
{
    /**
     * Null when there is no status file or it cannot be parsed. The file is
     * written atomically by the sidecar (temp + rename) so a half-written
     * read should not happen, but a parse failure is still just "unknown".
     */
    public static function run(): ?SidecarStatus
    {
        $path = SidecarPaths::statusFile();

        if (! is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);

        if ($raw === false || $raw === '') {
            return null;
        }

        try {
            $json = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (! is_array($json)) {
            return null;
        }

        try {
            return SidecarStatus::fromArray($json);
        } catch (\Throwable) {
            // Malformed field (e.g. an unparsable heartbeat) in a status
            // file written by an external process. Treat as unknown status
            // rather than letting it propagate into the pipeline tick.
            return null;
        }
    }
}
