<?php

namespace App\Actions;

use Illuminate\Support\Facades\Storage;

class ParseAndCacheLog
{
    public static function execute(string $logPath): string
    {
        $entries = [];
        $current = null;
        $timestampPattern = '/^(\d{2}:\d{2}:\d{2})\s+\[([A-Z]+)\]/';

        $handle = fopen($logPath, 'r');

        $dateTime = now()->parse(filemtime($logPath));

        if (! $handle) {
            throw new \RuntimeException("Cannot open log file: {$logPath}");
        }

        $sessionId = null;

        while (($line = fgets($handle)) !== false) {
            $trim = rtrim($line, "\r\n");

            // Start of a new log entry
            if (preg_match($timestampPattern, $trim, $m)) {
                if ($current !== null) {
                    $entries[] = $current;
                }

                // Can we get the session ID?
                preg_match('/\(Initialization\|SessionStarted\) ID: ([^\s]+)$/', $trim, $match);

                if (! empty($match)) {
                    $sessionId = $match[1];
                }

                $current = [
                    'timestamp' => $m[1],
                    'level' => $m[2],
                    'content' => $trim,
                ];

                continue;
            }

            // Append continuation line
            if ($current !== null) {
                $current['content'] .= "\n".$trim;
            }
        }

        // Flush last entry
        if ($current !== null) {
            $entries[] = $current;
        }

        fclose($handle);

        // Save JSON to storage
        $jsonName = $sessionId.'.json';

        Storage::put("mtgo_logs/{$jsonName}", json_encode([
            'date' => $dateTime->format('Y-m-d H:i:s'),
            'entries' => $entries,
        ], JSON_PRETTY_PRINT));

        return "mtgo_logs/{$jsonName}";
    }
}
