<?php

namespace App\Jobs;

use App\Actions\Import\PopulateCardsInChunks;
use App\Actions\Matches\ParseGameHistory;
use App\Models\ImportScan;
use App\Models\MtgoMatch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ParseAndFilterHistoryJob implements ShouldQueue
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

        $scan->markStage('parsing');

        // Allow test injection of history records
        $historyRecords = app()->bound('import.history_records')
            ? app('import.history_records')
            : ParseGameHistory::parse();

        if (empty($historyRecords)) {
            $scan->update(['status' => 'complete', 'total' => 0]);

            return;
        }

        $existingMtgoIds = MtgoMatch::pluck('mtgo_id')->filter()->toArray();
        $newRecords = array_values(array_filter(
            $historyRecords,
            fn ($r) => ! in_array($r['Id'], $existingMtgoIds)
        ));

        if (empty($newRecords)) {
            $scan->update(['status' => 'complete', 'total' => 0]);

            return;
        }

        $scan->update(['total' => count($newRecords)]);

        // Populate cards needed for confidence scoring
        PopulateCardsInChunks::run();

        MatchAndScoreJob::dispatch($this->scanId);
    }

    public function failed(\Throwable $e): void
    {
        ImportScan::find($this->scanId)?->markFailed($e->getMessage());

        Log::channel('pipeline')->error('ParseAndFilterHistoryJob failed', [
            'scan_id' => $this->scanId,
            'error' => $e->getMessage(),
        ]);
    }
}
