<?php

namespace App\Actions\Logs;

use App\Facades\Mtgo;
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
        $finder = Finder::create()
            ->files()
            ->name('mtgo.log')
            ->in(Mtgo::getLogPath())
            ->ignoreUnreadableDirs()
            ->sortByModifiedTime()
            ->reverseSorting()
            ->depth('< 8');

        foreach ($finder as $file) {
            return $file->getRealPath();
        }

        return null;
    }
}
