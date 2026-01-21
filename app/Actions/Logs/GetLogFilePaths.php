<?php

namespace App\Actions\Logs;

use Illuminate\Support\Facades\Storage;

class GetLogFilePaths
{
    public static function run(string $path)
    {
        $logPaths = collect();

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(Storage::disk('user_home')->path('\\AppData\\Local\\Apps\\2.0'), \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (str_contains($file->getFilename(), 'mtgo.log')) {
                    $logPaths->push([
                        'path' => $file->getPathname(),
                        'modified_at' => now()->parse($file->getMTime()),
                    ]);
                }
            }
        } catch (\Throwable $e) {
        }

        return $logPaths;
    }
}
