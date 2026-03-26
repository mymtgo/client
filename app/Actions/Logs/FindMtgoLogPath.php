<?php

namespace App\Actions\Logs;

use App\Facades\Mtgo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Finder\Finder;

class FindMtgoLogPath
{
    public static function run(): ?string
    {
        return Cache::remember('mtgo.active_log_path', now()->addSeconds(60), function () {
            return static::scan();
        });
    }

    public static function scan(): ?string
    {
        return static::all()->last();
    }

    /**
     * Return all mtgo.log paths sorted oldest-first by mtime.
     *
     * @return Collection<int, string>
     */
    public static function all(): Collection
    {
        return Cache::remember('mtgo.all_log_paths', now()->addSeconds(60), function () {
            return static::scanAll();
        });
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
