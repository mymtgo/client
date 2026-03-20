<?php

namespace App\Actions\Pipeline;

use App\Actions\Matches\ParseGameLogBinary;
use App\Models\GameLog;
use App\Models\GameLogCursor;
use App\Models\MtgoMatch;
use Illuminate\Support\Facades\Log;

class ArchiveGameLog
{
    public static function run(MtgoMatch $match): void
    {
        // Check if already archived
        $existing = GameLog::where('match_token', $match->token)->first();

        if ($existing && ! empty($existing->decoded_entries)) {
            return;
        }

        // Find the .dat file — look in known locations
        $datPath = self::findDatFile($match->token);

        if (! $datPath) {
            Log::channel('pipeline')->warning('ArchiveGameLog: no .dat file found', [
                'token' => $match->token,
            ]);

            return;
        }

        $raw = @file_get_contents($datPath);

        if (! $raw) {
            return;
        }

        $parsed = ParseGameLogBinary::run($raw);

        if (! $parsed || empty($parsed['entries'])) {
            return;
        }

        if ($existing) {
            $existing->update([
                'decoded_entries' => $parsed['entries'],
                'decoded_at' => now(),
            ]);
        } else {
            GameLog::create([
                'match_token' => $match->token,
                'file_path' => $datPath,
                'decoded_entries' => $parsed['entries'],
                'decoded_at' => now(),
            ]);
        }

        Log::channel('pipeline')->info('ArchiveGameLog: archived game log', [
            'token' => $match->token,
            'entries' => count($parsed['entries']),
        ]);
    }

    /**
     * Locate the .dat file for a match token.
     */
    private static function findDatFile(string $token): ?string
    {
        // Check GameLog table for known file path
        $gameLog = GameLog::where('match_token', $token)->first();

        if ($gameLog && $gameLog->file_path && is_file($gameLog->file_path)) {
            return $gameLog->file_path;
        }

        // Check GameLogCursor table
        $cursor = GameLogCursor::where('match_token', $token)->first();

        if ($cursor && is_file($cursor->file_path)) {
            return $cursor->file_path;
        }

        return null;
    }
}
