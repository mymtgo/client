<?php

namespace App\Jobs;

use App\Actions\Matches\ParseGameLogBinary;
use App\Models\GameLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ReDecodeGameLogsJob implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('updates');
    }

    public function handle(): void
    {
        $dispatched = 0;

        GameLog::where('decoded_version', '<', ParseGameLogBinary::VERSION)
            ->whereNotNull('file_path')
            ->chunkById(100, function ($gameLogs) use (&$dispatched) {
                foreach ($gameLogs as $gameLog) {
                    ReDecodeSingleGameLogJob::dispatch($gameLog->id);
                    $dispatched++;
                }
            });

        Log::info("ReDecodeGameLogsJob: dispatched {$dispatched} game logs for re-decoding");
    }
}
