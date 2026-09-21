<?php

namespace App\Actions\Sidecar;

use App\Models\GameEvent;
use App\Sidecar\SidecarAuthorityFlags;
use App\Sidecar\SidecarTables;

class ResolveSidecarUsername
{
    /**
     * Latest verified probe's username from the LIVE sidecar session, or
     * null when the flag is off, the sidecar isn't attached (no status
     * file, or a stale heartbeat), or no verified probe exists for that
     * session. Bound to the session named in the live status file's
     * `current_file` (format `events-{session}.ndjson`), not just "the
     * latest probe ever" seen in the table, so a verified probe from a
     * long-dead prior session can never override a freshly switched
     * account.
     *
     * Cheap: one status-file read plus at most one indexed query on
     * `(type, session, seq)` (`game_events_probe_idx`). Deliberately not
     * memoized: the pipeline queue worker is one long-lived PHP process, so
     * once() would pin the first result for the worker's lifetime.
     *
     * Wrapped in try/catch: `game_events` may not exist yet on an install
     * whose version-gated migration hasn't run (see
     * MtgoManager::getLogDataPath() for the same defensive pattern), and a
     * lookup failure here must never break getUsername().
     */
    public static function run(): ?string
    {
        try {
            return self::resolve();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function resolve(): ?string
    {
        if (! SidecarTables::ready()) {
            return null;
        }

        if (! SidecarAuthorityFlags::isOn('username')) {
            return null;
        }

        $status = ReadSidecarStatus::run();

        if ($status === null || $status->isStale(120)) {
            return null;
        }

        $session = self::sessionFromCurrentFile($status->currentFile);

        if ($session === null) {
            return null;
        }

        $data = GameEvent::query()
            ->where('type', 'probe')
            ->where('verified', true)
            ->where('session', $session)
            ->orderByDesc('seq')
            ->value('data');

        $username = is_array($data) ? ($data['username'] ?? null) : null;

        return is_string($username) && $username !== '' ? $username : null;
    }

    private static function sessionFromCurrentFile(?string $currentFile): ?string
    {
        if ($currentFile === null
            || ! str_starts_with($currentFile, 'events-')
            || ! str_ends_with($currentFile, '.ndjson')) {
            return null;
        }

        return substr($currentFile, strlen('events-'), -strlen('.ndjson'));
    }
}
