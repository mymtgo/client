<?php

namespace App\Actions\Logs;

class GetLogFilePaths
{
    public static function run(string $path)
    {
        $logPaths = collect();

        if (empty($path) || ! is_dir($path)) {
            return $logPaths;
        }

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
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
