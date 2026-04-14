<?php

namespace App\Jobs;

use App\Actions\Cards\DownloadCardImage;
use App\Actions\RegisterDevice;
use App\Models\Card;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Native\Desktop\Facades\Settings;

class BackfillCardDetails implements ShouldQueue
{
    use Queueable;

    /** API calls for card enrichment — retry with backoff. */
    public int $tries = 3;

    /** @var int[] */
    public array $backoff = [10, 60];

    /**
     * Re-fetch card data from the API in batches to backfill new fields.
     */
    public function handle(): void
    {
        $cards = Card::query()
            ->whereNotNull('name')
            ->whereNull('art_crop')
            ->get();

        if ($cards->isEmpty()) {
            Log::info('BackfillCardDetails: no cards to backfill');

            return;
        }

        Log::info("BackfillCardDetails: backfilling {$cards->count()} cards");

        $this->downloadImages = (bool) Settings::get('local_images');

        $regularCards = $cards->where('rarity', '!=', 'token');
        $tokenCards = $cards->where('rarity', 'token');

        foreach ($regularCards->chunk(300) as $chunk) {
            $this->updateRegularCards($chunk);
        }

        foreach ($tokenCards->chunk(300) as $chunk) {
            $this->updateTokenCards($chunk);
        }
    }

    private bool $downloadImages = false;

    /**
     * @param  Collection<int, Card>  $cards
     */
    private function updateRegularCards($cards): void
    {
        $response = Http::withHeaders([
            'X-Device-Id' => Settings::get('device_id'),
            'X-Api-Key' => RegisterDevice::retrieveKey(),
        ])->post(config('mymtgo_api.url').'/api/cards', [
            'ids' => $cards->pluck('mtgo_id')->values(),
        ]);

        $cardsResponse = collect($response->json());

        foreach ($cards as $card) {
            $cardData = $cardsResponse->first(
                fn ($data) => ($data['value'] ?? null) == $card->mtgo_id
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
                'colors' => $cardData['colors'] ?? null,
                'cmc' => $cardData['cmc'] ?? null,
                'set_name' => $cardData['set_name'] ?? null,
                'set_code' => $cardData['set'] ?? null,
                'art_crop' => $cardData['art_crop'] ?? null,
                'image' => $cardData['image'],
            ]);

            if ($this->downloadImages) {
                DownloadCardImage::run($card);
            }
        }
    }

    /**
     * @param  Collection<int, Card>  $cards
     */
    private function updateTokenCards($cards): void
    {
        $response = Http::withHeaders([
            'X-Device-Id' => Settings::get('device_id'),
            'X-Api-Key' => RegisterDevice::retrieveKey(),
        ])->post(config('mymtgo_api.url').'/api/cards', [
            'tokens' => $cards->pluck('name')->unique()->values(),
        ]);

        $cardsResponse = collect($response->json());

        foreach ($cards as $card) {
            $cardData = $cardsResponse->first(
                fn ($data) => ($data['layout'] ?? null) === 'token' && ($data['name'] ?? null) === $card->name
            );

            if (! $cardData) {
                continue;
            }

            $card->update([
                'scryfall_id' => $cardData['scryfall_id'],
                'oracle_id' => $cardData['oracle_id'],
                'type' => $cardData['type'] ?? $card->type,
                'sub_type' => $cardData['sub_type'] ?? $card->sub_type,
                'color_identity' => $cardData['color_identity']
                    ? collect(explode(',', $cardData['color_identity']))->map(fn ($c) => ! $c ? 'C' : $c)->join(',')
                    : $card->color_identity,
                'colors' => $cardData['colors'] ?? null,
                'cmc' => $cardData['cmc'] ?? null,
                'set_name' => $cardData['set_name'] ?? null,
                'set_code' => $cardData['set'] ?? null,
                'art_crop' => $cardData['art_crop'] ?? null,
                'image' => $cardData['image'],
            ]);

            if ($this->downloadImages) {
                DownloadCardImage::run($card);
            }
        }
    }
}
