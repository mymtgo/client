<?php

namespace App\Jobs;

use App\Actions\Cards\CreateMissingCardsFromTimelines;
use App\Actions\Cards\DownloadCardImage;
use App\Actions\Cards\PopulateTokensFromXml;
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

    /**
     * How far below a back face's CatalogID its front face can sit in MTGO.
     *
     * MTGO allocates a nonfoil id then a foil id, so a back face usually
     * lands two above its front. A set issued without foil ids closes that
     * gap and the back face sits one above instead. Two is tried first
     * because where both exist they are the same printing, and where they
     * differ two is the pair that actually holds a foil id between them.
     *
     * @var list<int>
     */
    private const FRONT_FACE_OFFSETS = [2, 1];

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

        $nameless = Card::whereNull('name')->get();

        // First pass: identify tokens from local MTGO XMLs. Only nameless rows
        // can gain anything here, but an empty set must not end the job: a row
        // can carry a name and still be missing its scryfall data, and that is
        // exactly what the passes below exist to fix.
        if ($nameless->isNotEmpty()) {
            PopulateTokensFromXml::run($nameless);
        }

        // Re-query cards still missing scryfall_id (tokens now have names but still need API data)
        $unresolved = Card::whereNull('scryfall_id')->get();

        if ($unresolved->isEmpty()) {
            return;
        }

        $regularCards = $unresolved->whereNull('rarity')->merge($unresolved->where('rarity', '!=', 'token'));
        $tokenCards = $unresolved->where('rarity', 'token')->whereNotNull('name');

        $downloadImages = AppSettings::downloadImagesLocally();

        // Process regular cards in batches to avoid overwhelming the API
        $regularCards->chunk(50)->each(function (Collection $chunk) use ($downloadImages) {
            $this->fetchAndUpdate($chunk, collect(), $downloadImages);
        });

        // Process tokens in a single call (small set of unique names)
        if ($tokenCards->isNotEmpty()) {
            $this->fetchAndUpdate(collect(), $tokenCards, $downloadImages);
        }

        $this->resolveBackFaces($downloadImages);
        $this->resolveByName($downloadImages);
    }

    /**
     * Last pass: ask by name for what no catalog id can reach.
     *
     * MTGO issues a separate catalog id for each half of a split card, each
     * adventure, and every token, and Scryfall records none of them: it holds
     * one mtgo_id per printing, for the card as a whole. "Tear" (48556) and
     * "Wear" (48394) are 162 apart and neither appears in any catalog, so no
     * id and no offset can find them. The name from the game log is the only
     * key left, and the row keeps its own mtgo_id.
     */
    private function resolveByName(bool $downloadImages): void
    {
        $unresolved = Card::whereNull('scryfall_id')->whereNotNull('name')->get();

        if ($unresolved->isEmpty()) {
            return;
        }

        $unresolved->chunk(100)->each(function (Collection $chunk) use ($downloadImages) {
            try {
                $response = $this->apiClient()->post('/api/cards', [
                    'ids' => [],
                    'tokens' => [],
                    'names' => $chunk->pluck('name')->unique()->values(),
                ]);

                foreach (collect($response->json()) as $cardData) {
                    $query = $cardData['query'] ?? null;

                    if ($query === null) {
                        continue;
                    }

                    // One answer can serve several rows: a deck can hold more
                    // than one printing of the same token.
                    foreach ($chunk->where('name', $query) as $card) {
                        $this->updateCard($card, $cardData, $downloadImages);
                    }
                }
            } catch (\Throwable $e) {
                report($e);
            }
        });
    }

    /**
     * Second pass for the rows the reference API does not know.
     *
     * MTGO gives a multi-face printing's back face its own CatalogID, two
     * above the front face's, and the reference catalog only indexes the
     * front. A row for "Boggart Bog" (126519) therefore never resolves on
     * its own id, and every run re-asks for an id that will never be known,
     * so the card sits in the missing count forever.
     *
     * Retrying two below recovers the whole class. The offset alone is not
     * proof though: for a single-faced card it lands on an unrelated
     * printing, so only a genuinely multi-face answer is accepted. The row
     * keeps its own mtgo_id and takes the pair's name, art and oracle id,
     * which is what Scryfall holds for a double-faced card anyway.
     */
    private function resolveBackFaces(bool $downloadImages): void
    {
        foreach (self::FRONT_FACE_OFFSETS as $offset) {
            $unresolved = Card::whereNull('scryfall_id')
                ->get()
                ->filter(fn (Card $card) => ((int) $card->mtgo_id) > $offset);

            if ($unresolved->isEmpty()) {
                return;
            }

            $unresolved->chunk(50)->each(fn (Collection $chunk) => $this->resolveBackFaceChunk($chunk, $offset, $downloadImages));
        }
    }

    /**
     * @param  Collection<int, Card>  $chunk
     */
    private function resolveBackFaceChunk(Collection $chunk, int $offset, bool $downloadImages): void
    {
        $byFrontId = $chunk->keyBy(fn (Card $card) => ((int) $card->mtgo_id) - $offset);

        try {
            $response = $this->apiClient()->post('/api/cards', [
                'ids' => $byFrontId->keys()->values(),
                'tokens' => [],
            ]);

            foreach (collect($response->json()) as $cardData) {
                $card = $byFrontId->get((int) ($cardData['value'] ?? 0));

                // A single-faced answer means the offset landed on a
                // neighbouring printing, not this card's front face.
                if (! $card || ! str_contains((string) ($cardData['name'] ?? ''), ' // ')) {
                    continue;
                }

                $this->updateCard($card, $cardData, $downloadImages);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Build a fresh client per request rather than reusing one across chunks.
     *
     * The macro resolves the API key when the closure runs, so a builder held
     * across multiple sends would keep using whatever key was current when it
     * was first built. This job can run long enough for the key to expire
     * mid-run, so each chunk needs its own client to pick up a re-registered
     * key.
     */
    private function apiClient(): PendingRequest
    {
        return Http::mymtgoReference()->timeout(15);
    }

    /**
     * @param  Collection<int, Card>  $regularCards
     * @param  Collection<int, Card>  $tokenCards
     */
    private function fetchAndUpdate(Collection $regularCards, Collection $tokenCards, bool $downloadImages): void
    {
        if ($regularCards->isEmpty() && $tokenCards->isEmpty()) {
            return;
        }

        try {
            $response = $this->apiClient()->post('/api/cards', [
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
            'mana_cost' => $cardData['mana_cost'] ?? null,
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
