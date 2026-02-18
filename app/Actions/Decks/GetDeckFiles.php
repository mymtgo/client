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

        $finder = Finder::create()
            ->files()
            ->in($basePath)
            ->ignoreUnreadableDirs()
            ->name('/^grouping ([0-9a-f-]{36})\.xml$/i');

        $latest = []; // deckUuid => ['path', 'mtime', 'size']

        foreach ($finder as $file) {
            if (! preg_match('/^grouping ([0-9a-f-]{36})\.xml$/i', $file->getFilename(), $m)) {
                continue;
            }

            $deckUuid = strtolower($m[1]);

            $candidate = [
                'path' => $file->getPathname(),
                'mtime' => $file->getMTime(),
                'size' => $file->getSize(),
            ];

            if (! isset($latest[$deckUuid])) {
                $latest[$deckUuid] = $candidate;

                continue;
            }

            $current = $latest[$deckUuid];

            // newest mtime wins; size breaks rare ties
            if (
                $candidate['mtime'] > $current['mtime'] ||
                ($candidate['mtime'] === $current['mtime'] && $candidate['size'] > $current['size'])
            ) {
                $latest[$deckUuid] = $candidate;
            }
        }

        return array_values(array_map(fn ($v) => $v['path'], $latest));
    }
}
