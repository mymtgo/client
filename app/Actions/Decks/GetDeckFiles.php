<?php

namespace App\Actions\Decks;

use App\Facades\Mtgo;
use Symfony\Component\Finder\Finder;

class GetDeckFiles
{
    public static function run(): array
    {
        $basePath = Mtgo::getLogDataPath();

        if (empty($basePath) || ! is_dir($basePath)) {
            return [];
        }

        $activeDir = static::findActiveDirectory($basePath);

        if (! $activeDir) {
            return [];
        }

        $finder = Finder::create()
            ->files()
            ->in($activeDir)
            ->ignoreUnreadableDirs()
            ->depth(0)
            ->name('/^grouping ([0-9a-f-]{36})\.xml$/i');

        $files = [];

        foreach ($finder as $file) {
            if (preg_match('/^grouping ([0-9a-f-]{36})\.xml$/i', $file->getFilename())) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * Find the active account's hash directory by looking for the most
     * recently modified user_settings file across all subdirectories.
     */
    protected static function findActiveDirectory(string $basePath): ?string
    {
        $finder = Finder::create()
            ->files()
            ->in($basePath)
            ->ignoreUnreadableDirs()
            ->depth(1)
            ->name('user_settings')
            ->sortByModifiedTime()
            ->reverseSorting();

        foreach ($finder as $file) {
            return $file->getPath();
        }

        return null;
    }
}
