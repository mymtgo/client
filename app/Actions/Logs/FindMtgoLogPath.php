<?php

namespace App\Actions\Logs;

use App\Facades\Mtgo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Finder\Finder;

class FindMtgoLogPath
{
    /**
     * Return all mtgo.log paths sorted oldest-first by mtime.
     *
     * @return Collection<int, string>
     */
    public const CACHE_KEY = 'mtgo.all_log_paths';

    public const CACHE_TTL_SECONDS = 5;

    public static function all(): Collection
    {
        return Cache::remember(self::CACHE_KEY, now()->addSeconds(self::CACHE_TTL_SECONDS), function () {
            return static::scanAll();
        });
    }

    public static function forgetCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return Collection<int, string>
     */
    public static function scanAll(): Collection
    {
        $finder = Finder::create()
            ->files()
            ->name('mtgo.log')
            ->in(Mtgo::getLogPath())
            ->ignoreUnreadableDirs()
            ->sortByModifiedTime()
            ->depth('< 8');

        $paths = collect();

        foreach ($finder as $file) {
            $paths->push($file->getRealPath());
        }

        return $paths;
    }
}
