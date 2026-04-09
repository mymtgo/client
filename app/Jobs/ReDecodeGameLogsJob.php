<?php

namespace App\Jobs;

use App\Actions\Matches\ParseGameLogBinary;
use App\Models\GameLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Native\Desktop\Facades\Settings;

class ReDecodeGameLogsJob implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('updates');
    }

    public function handle(): void
    {
        $timezone = Settings::get('system_tz', 'UTC');

        GameLog::where('decoded_version', '<', ParseGameLogBinary::VERSION)
            ->whereNotNull('file_path')
            ->chunkById(100, function ($gameLogs) use ($timezone) {
                foreach ($gameLogs as $gameLog) {
                    ReDecodeSingleGameLogJob::dispatch($gameLog->id, $timezone);
                }
            });
    }
}
