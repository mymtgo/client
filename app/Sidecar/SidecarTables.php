<?php

namespace App\Sidecar;

use Illuminate\Support\Facades\Schema;

/**
 * Cheap "have the sidecar migrations run yet?" gate.
 *
 * Migrations are version-gated (see the NATIVEPHP_APP_VERSION notes in
 * docs/pipelines.md), so a branch build that ships the sidecar code without
 * a version bump runs against a database where `game_events` and
 * `game_field_diffs` do not exist. Every sidecar entry point consults this
 * first and returns its empty value instead of throwing a QueryException
 * once per match per pipeline tick.
 *
 * Only a positive result is cached. The tables can appear during the life
 * of the process (migrations run at boot before the queue worker starts)
 * but they can never disappear, so a true is permanent and a false is
 * re-checked on every call.
 */
class SidecarTables
{
    private static bool $ready = false;

    public static function ready(): bool
    {
        if (self::$ready) {
            return true;
        }

        try {
            self::$ready = Schema::hasTable('game_events') && Schema::hasTable('game_field_diffs');
        } catch (\Throwable) {
            return false;
        }

        return self::$ready;
    }

    /** Clears the positive cache. Tests that drop the tables must call this. */
    public static function reset(): void
    {
        self::$ready = false;
    }
}
