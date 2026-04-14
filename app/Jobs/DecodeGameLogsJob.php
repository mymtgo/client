<?php

namespace App\Jobs;

use App\Actions\Matches\ExtractGameResults;
use App\Actions\Matches\ParseGameLogBinary;
use App\Models\GameLog;
use App\Models\ImportScan;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class DecodeGameLogsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int> */
    public array $backoff = [2, 5];

    public function __construct(
        public int $scanId,
    ) {
        $this->onQueue('importer');
    }

    public function handle(): void
    {
        $scan = ImportScan::find($this->scanId);

        if (! $scan || $scan->isCancelled()) {
            return;
        }

        // Phase 1: Decode undecoded game logs
        $undecoded = GameLog::whereNull('decoded_entries')->count();
        $scan->markStage('decoding', $undecoded);

        if ($undecoded > 0) {
            $decoded = 0;

            GameLog::whereNull('decoded_entries')
                ->chunkById(100, function ($logs) use ($scan, &$decoded) {
                    foreach ($logs as $gameLog) {
                        if ($scan->fresh()->isCancelled()) {
                            return false;
                        }

                        $this->decodeLog($gameLog);
                        $decoded++;
                    }

                    $scan->update(['progress' => $decoded]);
                });

            if ($scan->fresh()->isCancelled()) {
                return;
            }
        }

        // Phase 2: Backfill first_timestamp/players for any logs decoded before the migration
        GameLog::whereNotNull('decoded_entries')
            ->whereNull('first_timestamp')
            ->chunkById(100, function ($logs) use ($scan) {
                foreach ($logs as $gameLog) {
                    if ($scan->fresh()->isCancelled()) {
                        return false;
                    }

                    $entries = $gameLog->decoded_entries;
                    if (empty($entries)) {
                        continue;
                    }

                    $players = ExtractGameResults::detectPlayers($entries);

                    $gameLog->update([
                        'first_timestamp' => $entries[0]['timestamp'] ?? null,
                        'players' => $players,
                    ]);
                }
            });

        if ($scan->fresh()->isCancelled()) {
            return;
        }

        ParseAndFilterHistoryJob::dispatch($this->scanId);
    }

    private function decodeLog(GameLog $gameLog): void
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
            Log::channel('pipeline')->warning("DecodeGameLogsJob: failed to decode {$gameLog->file_path}", [
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function failed(\Throwable $e): void
    {
        ImportScan::find($this->scanId)?->markFailed($e->getMessage());

        Log::channel('pipeline')->error('DecodeGameLogsJob failed', [
            'scan_id' => $this->scanId,
            'error' => $e->getMessage(),
        ]);
    }
}
