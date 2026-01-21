<?php

namespace App\Actions;

use Illuminate\Support\Facades\Storage;

class ProcessRawLogFile
{
    public static function run(): array
    {
        $basePath = Storage::disk('user_home')->path('\\AppData\\Local\\Apps\\2.0');

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($basePath, \FilesystemIterator::SKIP_DOTS)
        );

        $logPaths = [];

        foreach ($iterator as $file) {
            if (str_contains($file->getFilename(), 'mtgo.log')) {
                $logPaths[] = [
                    'path' => $file->getPathname(),
                    'modified_at' => now()->parse($file->getMTime()),
                ];
            }
        }

        $storedLogs = [];

        foreach ($logPaths as $logPath) {
            $storedLogs[] = [
                'path' => ParseAndCacheLog::execute($logPath['path']),
                'modified_at' => $logPath['modified_at'],
            ];
        }

        return $storedLogs;
    }
}
