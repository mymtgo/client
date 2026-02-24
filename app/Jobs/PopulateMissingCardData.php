<?php

namespace App\Jobs;

use App\Models\Card;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;

class PopulateMissingCardData implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $cards = Card::whereNull('scryfall_id')->get();

        $ids = $cards->pluck('mtgo_id');

        $response = Http::post(config('mymtgo_api.url').'/api/cards', [
            'ids' => $ids,
        ]);

        $cardsResponse = collect($response->json());

        foreach ($cards as $card) {

            $cardData = $cardsResponse->first(
                fn ($data) => $data['value'] == $card->mtgo_id
            );

            if (! $cardData) {
                continue;
            }

            $card->update([
                'scryfall_id' => $cardData['scryfall_id'],
                'oracle_id' => $cardData['oracle_id'],
                'name' => $cardData['name'],
                'type' => $cardData['type'],
                'sub_type' => $cardData['sub_type'],
                'rarity' => $cardData['rarity'],
                'color_identity' => collect(explode(',', $cardData['color_identity']))->map(function ($color) {
                    return ! $color ? 'C' : $color;
                })->join(','),
                'image' => $cardData['image'],
            ]);
        }
    }
}
