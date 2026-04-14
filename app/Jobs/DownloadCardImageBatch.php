<?php

namespace App\Jobs;

use App\Actions\Cards\DownloadCardImage;
use App\Models\Card;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class DownloadCardImageBatch implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var int[] */
    public array $backoff = [10, 60];

    /**
     * @param  int[]  $cardIds
     */
    public function __construct(public array $cardIds)
    {
        $this->onQueue('card_downloads');
    }

    public function handle(): void
    {
        $cards = Card::whereIn('id', $this->cardIds)->get();

        Log::info("DownloadCardImageBatch: downloading images for {$cards->count()} cards");

        foreach ($cards as $card) {
            DownloadCardImage::run($card);
        }
    }
}
