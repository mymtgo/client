<?php

namespace App\Jobs;

use App\Actions\Matches\ExtractGameResults;
use App\Actions\Matches\ParseGameLogBinary;
use App\Models\GameLog;
use App\Models\MtgoMatch;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ReDecodeSingleGameLogJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $gameLogId,
    ) {
        $this->onQueue('updates');
    }

    public function handle(): void
    {
        $gameLog = GameLog::find($this->gameLogId);

        if (! $gameLog) {
            return;
        }

        if (! $gameLog->file_path || ! file_exists($gameLog->file_path)) {
            return;
        }

        try {
            $raw = file_get_contents($gameLog->file_path);
            $parsed = ParseGameLogBinary::run($raw);

            if (! $parsed || empty($parsed['entries'])) {
                return;
            }

            $players = ExtractGameResults::detectPlayers($parsed['entries']);

            $gameLog->update([
                'decoded_entries' => $parsed['entries'],
                'decoded_at' => now(),
                'byte_offset' => $parsed['byte_offset'],
                'decoded_version' => ParseGameLogBinary::VERSION,
                'first_timestamp' => $parsed['entries'][0]['timestamp'] ?? null,
                'players' => $players,
            ]);

            $this->fixMatchTimestamps($gameLog, $parsed['entries']);
        } catch (\Throwable $e) {
            Log::warning("ReDecodeSingleGameLogJob: failed to re-decode {$gameLog->file_path}", [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function fixMatchTimestamps(GameLog $gameLog, array $entries): void
    {
        $match = MtgoMatch::where('token', $gameLog->match_token)->first();

        if (! $match) {
            return;
        }

        $match->update([
            'started_at' => Carbon::parse($entries[0]['timestamp']),
            'ended_at' => $match->ended_at ? Carbon::parse(end($entries)['timestamp']) : null,
        ]);

        $this->fixGameTimestamps($match, $entries);
    }

    private function fixGameTimestamps(MtgoMatch $match, array $entries): void
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

            $game->update([
                'started_at' => Carbon::parse($gameEntries[0]['timestamp']),
                'ended_at' => Carbon::parse(end($gameEntries)['timestamp']),
            ]);
        }
    }
}
