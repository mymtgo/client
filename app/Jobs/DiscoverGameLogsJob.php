<?php

namespace App\Jobs;

use App\Models\GameLog;
use App\Models\ImportScan;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Finder\Finder;

class DiscoverGameLogsJob implements ShouldQueue
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

        $directory = app('mtgo')->getLogDataPath();

        if (! $directory || ! is_dir($directory)) {
            $scan->markStage('discovering', 0);
            DecodeGameLogsJob::dispatch($this->scanId);

            return;
        }

        // Collect all file tokens and paths from disk
        $finder = (new Finder)
            ->files()
            ->in($directory)
            ->name('*Match_GameLog*')
            ->ignoreUnreadableDirs();

        $files = [];
        foreach ($finder as $file) {
            $parts = explode('_', $file->getFilenameWithoutExtension());
            $token = end($parts);
            $files[$token] = $file->getRealPath();
        }

        $total = count($files);
        $scan->markStage('discovering', $total);

        if ($total === 0) {
            DecodeGameLogsJob::dispatch($this->scanId);

            return;
        }

        // Process in chunks: check existing, bulk insert new
        $tokens = array_keys($files);
        $chunks = array_chunk($tokens, 500);
        $processed = 0;

        foreach ($chunks as $chunk) {
            if ($scan->fresh()->isCancelled()) {
                return;
            }

            $existing = GameLog::whereIn('match_token', $chunk)
                ->pluck('match_token')
                ->flip()
                ->all();

            $newRows = [];
            $now = now();

            foreach ($chunk as $token) {
                if (! isset($existing[$token])) {
                    $newRows[] = [
                        'match_token' => $token,
                        'file_path' => $files[$token],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            if (! empty($newRows)) {
                GameLog::insert($newRows);
            }

            $processed += count($chunk);
            $scan->update(['progress' => $processed]);
        }

        DecodeGameLogsJob::dispatch($this->scanId);
    }

    public function failed(\Throwable $e): void
    {
        ImportScan::find($this->scanId)?->markFailed($e->getMessage());

        Log::channel('pipeline')->error('DiscoverGameLogsJob failed', [
            'scan_id' => $this->scanId,
            'error' => $e->getMessage(),
        ]);
    }
}
