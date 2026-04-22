<?php

namespace App\Jobs;

use App\Actions\Matches\ParseGameLogBinary;
use App\Facades\AppSettings;
use App\Models\GameLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ReDecodeGameLogsJob implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('updates');
    }

    public function handle(): void
    {
        $timezone = AppSettings::systemTimezone();

        GameLog::where('decoded_version', '<', ParseGameLogBinary::VERSION)
            ->whereNotNull('file_path')
            ->chunkById(100, function ($gameLogs) use ($timezone) {
                foreach ($gameLogs as $gameLog) {
                    ReDecodeSingleGameLogJob::dispatch($gameLog->id, $timezone);
                }
            });
    }
}
