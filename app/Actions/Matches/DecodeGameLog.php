<?php

namespace App\Actions\Matches;

use App\Models\GameLog;
use Illuminate\Support\Facades\Log;

class DecodeGameLog
{
    /**
     * Decode a single GameLog's .dat file into decoded_entries (plus the
     * derived metadata columns). No-op when the file is missing. Failures are
     * logged and swallowed so a single bad file can't break batch decoding.
     */
    public static function run(GameLog $gameLog): void
    {
        if (! $gameLog->file_path || ! file_exists($gameLog->file_path)) {
            return;
        }

        try {
            $raw = file_get_contents($gameLog->file_path);
            $parsed = ParseGameLogBinary::run($raw);

            if ($parsed && ! empty($parsed['entries'])) {
                $players = ExtractGameResults::detectPlayers($parsed['entries']);

                $gameLog->update([
                    'decoded_entries' => $parsed['entries'],
                    'decoded_at' => now(),
                    'byte_offset' => $parsed['byte_offset'],
                    'decoded_version' => ParseGameLogBinary::VERSION,
                    'first_timestamp' => $parsed['entries'][0]['timestamp'] ?? null,
                    'players' => $players,
                ]);
            }
        } catch (\Throwable $e) {
            Log::channel('pipeline')->warning("DecodeGameLog: failed to decode {$gameLog->file_path}", [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
