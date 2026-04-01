<?php

namespace App\Updates;

use App\Models\GameLog;
use Illuminate\Support\Facades\Log;

class FixImportedGameLogs extends AppUpdate
{
    public function run(): void
    {
        $this->deleteEmptyGameLogs();
        $this->deduplicateGameLogs();
    }

    /**
     * Delete GameLog records that were never decoded.
     */
    private function deleteEmptyGameLogs(): void
    {
        $deleted = GameLog::whereNull('decoded_entries')->delete();

        Log::info("FixImportedGameLogs: deleted {$deleted} empty game log records");
    }

    /**
     * Remove duplicate GameLog entries, keeping only the decoded version.
     *
     * Discovery could create multiple records for the same match_token.
     * Keep the one with the largest decoded_entries payload.
     */
    private function deduplicateGameLogs(): void
    {
        $removed = 0;

        $duplicates = GameLog::selectRaw('match_token, COUNT(*) as cnt')
            ->whereNotNull('decoded_entries')
            ->groupBy('match_token')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('match_token');

        foreach ($duplicates as $token) {
            $logs = GameLog::where('match_token', $token)
                ->orderByRaw('LENGTH(decoded_entries) DESC')
                ->get();

            // Keep the first (largest), delete the rest
            foreach ($logs->skip(1) as $dupe) {
                $dupe->delete();
                $removed++;
            }
        }

        Log::info("FixImportedGameLogs: removed {$removed} duplicate game log records");
    }
}
