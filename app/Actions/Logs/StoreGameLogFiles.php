<?php

namespace App\Actions\Logs;

use App\Facades\Mtgo;
use App\Models\GameLog;
use Symfony\Component\Finder\Finder;

class StoreGameLogFiles
{
    public static function run()
    {
        $basePath = Mtgo::getLogDataPath();

        if (empty($basePath) || ! is_dir($basePath)) {
            return;
        }

        $finder = Finder::create()
            ->files()
            ->in($basePath)
            ->name('*Match_GameLog*')
            ->ignoreUnreadableDirs();

        $files = [];

        foreach ($finder as $file) {
            $files[] = $file->getRealPath();
        }

        foreach ($files as $file) {
            $nameParts = explode('_', $file);
            $matchToken = pathinfo(last($nameParts), PATHINFO_FILENAME);

            GameLog::firstOrCreate([
                'match_token' => $matchToken,
                'file_path' => $file,
            ]);
        }
    }
}
