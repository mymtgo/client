<?php

namespace App\Actions\Settings;

use Symfony\Component\Finder\Finder;

class ValidatePath
{
    /**
     * Validate that a log path exists and contains mtgo.log files.
     */
    public static function forLogs(string $path): array
    {
        if (empty($path) || ! is_dir($path)) {
            return [
                'valid' => false,
                'fileCount' => 0,
                'message' => empty($path) ? '' : 'Directory not found.',
            ];
        }

        try {
            $finder = Finder::create()
                ->files()
                ->in($path)
                ->name('mtgo.log')
                ->ignoreUnreadableDirs()
                ->depth('< 8');

            $count = iterator_count($finder);
        } catch (\Throwable) {
            $count = 0;
        }

        return [
            'valid' => $count > 0,
            'fileCount' => $count,
            'message' => $count > 0
                ? "Found {$count} log ".($count === 1 ? 'file' : 'files').'.'
                : 'No mtgo.log files found in this directory.',
        ];
    }

    /**
     * Validate that a data path exists and contains Match_GameLog_* files.
     */
    public static function forData(string $path): array
    {
        if (empty($path) || ! is_dir($path)) {
            return [
                'valid' => false,
                'fileCount' => 0,
                'message' => empty($path) ? '' : 'Directory not found.',
            ];
        }

        try {
            $finder = Finder::create()
                ->files()
                ->in($path)
                ->name('*Match_GameLog*')
                ->ignoreUnreadableDirs();

            $count = iterator_count($finder);
        } catch (\Throwable) {
            $count = 0;
        }

        return [
            'valid' => $count > 0,
            'fileCount' => $count,
            'message' => $count > 0
                ? "Found {$count} game log ".($count === 1 ? 'file' : 'files').'.'
                : 'No Match_GameLog files found in this directory.',
        ];
    }
}
