<?php

namespace App\Jobs;

use App\Models\Card;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class DownloadAllCardImages implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var int[] */
    public array $backoff = [30, 120];

    public function __construct()
    {
        $this->onQueue('card_downloads');
    }

    public function handle(): void
    {
        Card::query()
            ->whereNotNull('image')
            ->where(function ($query) {
                $query->whereNull('local_image')
                    ->orWhereNull('local_art_crop');
            })
            ->select('id')
            ->chunk(50, function ($cards) {
                DownloadCardImageBatch::dispatch($cards->pluck('id')->all());
            });

        Log::info('DownloadAllCardImages: dispatched batches');
    }
}
