<?php

namespace App\Jobs;

use App\Actions\Logs\ConvertMtgoTimestamp;
use App\Actions\Matches\ExtractGameResults;
use App\Models\AppSetting;
use App\Models\GameLog;
use App\Models\MtgoMatch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class FixMatchTimestampsJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        $fixed = 0;
        $skipped = 0;
        $userTz = AppSetting::displayTimezone();

        GameLog::whereNotNull('decoded_entries')
            ->whereNotNull('first_timestamp')
            ->chunkById(100, function ($gameLogs) use (&$fixed, &$skipped, $userTz) {
                foreach ($gameLogs as $gameLog) {
                    $match = MtgoMatch::where('token', $gameLog->match_token)->first();

                    if (! $match) {
                        $skipped++;

                        continue;
                    }

                    $entries = $gameLog->decoded_entries;

                    if (empty($entries)) {
                        $skipped++;

                        continue;
                    }

                    $matchStarted = ConvertMtgoTimestamp::fromDecodedEntry($entries[0]['timestamp'], $userTz);
                    $matchEnded = ConvertMtgoTimestamp::fromDecodedEntry(end($entries)['timestamp'], $userTz);

                    $match->update([
                        'started_at' => $matchStarted,
                        'ended_at' => $match->ended_at ? $matchEnded : null,
                    ]);

                    $this->fixGameTimestamps($match, $entries, $userTz);

                    $fixed++;
                }
            });

        Log::info("FixMatchTimestampsJob: corrected {$fixed} matches, skipped {$skipped}");
    }

    private function fixGameTimestamps(MtgoMatch $match, array $entries, string $userTz): void
    {
        $gameGroups = ExtractGameResults::splitIntoGames($entries);
        $games = $match->games()->orderBy('started_at')->orderBy('id')->get();

        foreach ($games as $index => $game) {
            if (! isset($gameGroups[$index])) {
                continue;
            }

            $gameEntries = $gameGroups[$index];

            if (empty($gameEntries)) {
                continue;
            }

            $gameStarted = ConvertMtgoTimestamp::fromDecodedEntry($gameEntries[0]['timestamp'], $userTz);
            $gameEnded = ConvertMtgoTimestamp::fromDecodedEntry(end($gameEntries)['timestamp'], $userTz);

            $game->update([
                'started_at' => $gameStarted,
                'ended_at' => $gameEnded,
            ]);
        }
    }
}
