<?php

namespace App\Jobs;

use App\Actions\Cards\CreateMissingCardsFromTimelines;
use App\Actions\Cards\DownloadCardImage;
use App\Actions\Cards\PopulateTokensFromXml;
use App\Actions\RegisterDevice;
use App\Facades\AppSettings;
use App\Models\Card;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

class PopulateMissingCardData implements ShouldQueue
{
    use Queueable;

    /** API calls for card enrichment — retry with backoff. */
    public int $tries = 3;

    /** @var int[] */
    public array $backoff = [10, 60];

    public function __construct()
    {
        $this->onQueue('card_downloads');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Create stubs for any CatalogIDs in timelines that don't have Card records yet
        // (tokens and other permanents that only appear in game state, not deck lists)
        CreateMissingCardsFromTimelines::run();

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

        $regularCards = $unresolved->whereNull('rarity')->merge($unresolved->where('rarity', '!=', 'token'));
        $tokenCards = $unresolved->where('rarity', 'token')->whereNotNull('name');

        $downloadImages = AppSettings::downloadImagesLocally();
        $http = $this->apiClient();

        // Process regular cards in batches to avoid overwhelming the API
        $regularCards->chunk(50)->each(function (Collection $chunk) use ($http, $downloadImages) {
            $this->fetchAndUpdate($http, $chunk, collect(), $downloadImages);
        });

        // Process tokens in a single call (small set of unique names)
        if ($tokenCards->isNotEmpty()) {
            $this->fetchAndUpdate($http, collect(), $tokenCards, $downloadImages);
        }
    }

    private function apiClient(): PendingRequest
    {
        return Http::withHeaders([
            'X-Device-Id' => AppSettings::deviceId(),
            'X-Api-Key' => RegisterDevice::retrieveKey(),
        ])->timeout(15);
    }

    /**
     * @param  Collection<int, Card>  $regularCards
     * @param  Collection<int, Card>  $tokenCards
     */
    private function fetchAndUpdate(PendingRequest $http, Collection $regularCards, Collection $tokenCards, bool $downloadImages): void
    {
        if ($regularCards->isEmpty() && $tokenCards->isEmpty()) {
            return;
        }

        try {
            $response = $http->post(config('mymtgo_api.url').'/api/cards', [
                'ids' => $regularCards->pluck('mtgo_id')->values(),
                'tokens' => $tokenCards->pluck('name')->unique()->values(),
            ]);

            $cardsResponse = collect($response->json());

            foreach ($regularCards as $card) {
                $cardData = $cardsResponse->first(
                    fn ($data) => ($data['value'] ?? null) == $card->mtgo_id
                );

                if ($cardData) {
                    $this->updateCard($card, $cardData, $downloadImages);
                }
            }

            foreach ($tokenCards as $card) {
                $cardData = $cardsResponse->first(
                    fn ($data) => ($data['layout'] ?? null) === 'token' && ($data['name'] ?? null) === $card->name
                );

                if ($cardData) {
                    $this->updateCard($card, $cardData, $downloadImages, isToken: true);
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * @param  array<string, mixed>  $cardData
     */
    private function updateCard(Card $card, array $cardData, bool $downloadImages, bool $isToken = false): void
    {
        $colorIdentity = $cardData['color_identity'] ?? null;
        $formattedColorIdentity = $colorIdentity
            ? collect(explode(',', $colorIdentity))->map(fn ($c) => ! $c ? 'C' : $c)->join(',')
            : ($isToken ? $card->color_identity : null);

        $card->update([
            'scryfall_id' => $cardData['scryfall_id'],
            'oracle_id' => $cardData['oracle_id'],
            'name' => $cardData['name'] ?? $card->name,
            'type' => $cardData['type'] ?? $card->type,
            'sub_type' => $cardData['sub_type'] ?? $card->sub_type,
            'rarity' => $cardData['rarity'] ?? $card->rarity,
            'color_identity' => $formattedColorIdentity,
            'colors' => $cardData['colors'] ?? null,
            'cmc' => $cardData['cmc'] ?? null,
            'set_name' => $cardData['set_name'] ?? null,
            'set_code' => $cardData['set'] ?? null,
            'art_crop' => $cardData['art_crop'] ?? null,
            'image' => $cardData['image'],
        ]);

        if ($downloadImages) {
            DownloadCardImage::run($card);
        }
    }
}
