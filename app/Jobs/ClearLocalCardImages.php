<?php

namespace App\Jobs;

use App\Models\Card;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ClearLocalCardImages implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct()
    {
        $this->onQueue('card_downloads');
    }

    public function handle(): void
    {
        Log::info('ClearLocalCardImages: removing local card images');

        $files = Storage::disk('cards')->allFiles();
        Storage::disk('cards')->delete($files);

        Card::query()
            ->where(function ($query) {
                $query->whereNotNull('local_image')
                    ->orWhereNotNull('local_art_crop');
            })
            ->update([
                'local_image' => null,
                'local_art_crop' => null,
            ]);

        Log::info('ClearLocalCardImages: complete');
    }
}
