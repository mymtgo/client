<?php

namespace App\Jobs;

use App\Actions\Import\ImportMatches;
use App\Models\ImportScan;
use App\Models\ImportScanMatch;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ImportMatchesJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<int>|null  $historyIds  Specific matches to import, or null for all
     */
    public function __construct(
        public int $scanId,
        public ?array $historyIds = null,
    ) {
        $this->onQueue('importer');
    }

    public function handle(): void
    {
        $scan = ImportScan::find($this->scanId);

        if (! $scan) {
            return;
        }

        $result = ImportMatches::runFromScan($scan, $this->historyIds);

        if ($result['imported'] > 0) {
            $query = ImportScanMatch::where('import_scan_id', $this->scanId);

            if ($this->historyIds !== null) {
                $query->whereIn('history_id', $this->historyIds);
            }

            $query->delete();
        }
    }
}
