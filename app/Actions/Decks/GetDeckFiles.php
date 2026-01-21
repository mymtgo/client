<?php

namespace App\Actions\Decks;

use App\Actions\Logs\FindMtgoLogPath;

class GetDeckFiles
{
    public static function run(): array
    {
        $logPath = FindMtgoLogPath::run();

        if (! $logPath) {
            return [];
        }

        // The decks are usually in a 'Data' folder sibling to the log file or in the same folder.
        // Based on the previous implementation, they were expected in .../2.0/Data/{random}/grouping...
        // However, MTGO usually keeps them in the same directory as the log or a sibling.

        $activeFolder = dirname($logPath);

        // MTGO sometimes stores decks in a sibling 'Data' folder
        // /AppData/Local/Apps/2.0/Data/{random}/grouping...
        // and logs in
        // /AppData/Local/Apps/2.0/{random}/mtgo.log

        $files = glob($activeFolder.DIRECTORY_SEPARATOR.'grouping *.xml');

        if (empty($files)) {
            $siblingData = dirname($activeFolder).DIRECTORY_SEPARATOR.'Data';
            if (is_dir($siblingData)) {
                $folders = glob($siblingData.DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR);
                foreach ($folders as $folder) {
                    $found = glob($folder.DIRECTORY_SEPARATOR.'grouping *.xml');
                    if (! empty($found)) {
                        $files = array_merge($files ?: [], $found);
                    }
                }
            }
        }

        if ($files === false) {
            return [];
        }

        $validDecks = [];
        foreach ($files as $filePath) {
            $filename = basename($filePath);
            if (preg_match('/^grouping ([0-9a-f-]{36})\.xml$/i', $filename)) {
                $validDecks[] = $filePath;
            }
        }

        return $validDecks;
    }
}
