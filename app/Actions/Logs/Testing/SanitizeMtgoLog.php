<?php

namespace App\Actions\Logs\Testing;

/**
 * Replace identifying values in an mtgo.log dump so it can be safely
 * checked in as a test fixture. Reusable for support tickets and
 * shared bug reports.
 */
class SanitizeMtgoLog
{
    /**
     * @param  array<string, string>  $usernameMap  real → fixture username
     * @param  array<string, string>  $matchTokenMap  real UUID → fixture UUID
     */
    public static function run(
        string $log,
        array $usernameMap = [],
        array $matchTokenMap = [],
    ): string {
        foreach ($usernameMap as $original => $replacement) {
            $log = str_replace($original, $replacement, $log);
        }

        foreach ($matchTokenMap as $original => $replacement) {
            $log = str_replace($original, $replacement, $log);
        }

        return $log;
    }
}
