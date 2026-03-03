<?php

namespace App\Jobs;

use App\Actions\Cards\PopulateTokensFromXml;
use App\Actions\RegisterDevice;
use App\Models\Card;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Native\Desktop\Facades\Settings;

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
        $cards = Card::whereNull('name')->get();

        if ($cards->isEmpty()) {
            return;
        }

        // First pass: identify tokens from local MTGO XMLs
        PopulateTokensFromXml::run($cards);

        // Re-query cards still missing scryfall_id (tokens now have names but still need API data)
        $unresolved = Card::whereNull('scryfall_id')->get();

        if ($unresolved->isEmpty()) {
            return;
        }

        // Split into regular cards and identified tokens
        $regularCards = $unresolved->whereNull('rarity')->merge($unresolved->where('rarity', '!=', 'token'));
        $tokenCards = $unresolved->where('rarity', 'token')->whereNotNull('name');

        try {
            $response = Http::withHeaders([
                'X-Device-Id' => Settings::get('device_id'),
                'X-Api-Key' => RegisterDevice::retrieveKey(),
            ])->post(config('mymtgo_api.url').'/api/cards', [
                'ids' => $regularCards->pluck('mtgo_id')->values(),
                'tokens' => $tokenCards->pluck('name')->unique()->values(),
            ]);

            $cardsResponse = collect($response->json());

            foreach ($regularCards as $card) {
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

            foreach ($tokenCards as $card) {
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
                    'image' => $cardData['image'],
                ]);
            }
        } catch (\Throwable) {
            // Network failure — tokens still have name/type from XML, API cards will retry next run
        }
    }
}
