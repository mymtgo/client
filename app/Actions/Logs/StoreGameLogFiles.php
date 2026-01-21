<?php

namespace App\Actions\Logs;

use App\Models\GameLog;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Finder\Finder;

class StoreGameLogFiles
{
    public static function run()
    {
        $basePath = Storage::disk('user_home')->path('AppData\\Local\\Apps\\2.0\\Data');

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
