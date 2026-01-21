<?php

namespace App\Actions;

use Illuminate\Support\Facades\Storage;
use Native\Laravel\Facades\Settings;

class SetMtgoUsernameFromLog
{
    public static function run(string $logPath)
    {
        $log = Storage::json($logPath);

        foreach ($log['entries'] as $entry) {
            preg_match(
                '/MtGO Login Last Success\)\s+Username:\s*(\S+)/',
                $entry['content'],
                $matches
            );

            if (count($matches)) {
                $username = trim($matches[1]);

                Settings::set('mtgo_username', $username);
            }
        }
    }
}
