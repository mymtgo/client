<?php

namespace App\Jobs;

use App\Actions\Import\ReprocessImportedCardData;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ReprocessImportedCardDataJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function handle(): void
    {
        $result = ReprocessImportedCardData::run();

        Log::info('ReprocessImportedCardData complete', $result);
    }
}
